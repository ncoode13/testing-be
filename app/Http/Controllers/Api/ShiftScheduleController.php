<?php

namespace App\Http\Controllers\Api;

use App\Exports\ShiftScheduleTemplateExport;
use App\Http\Controllers\Api\Controller as ApiController;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Imports\ShiftScheduleImport;
use Maatwebsite\Excel\Facades\Excel;

class ShiftScheduleController extends ApiController
{

    public function index(Request $request)
    {
        $user = Auth::user();

        $month = (int) $request->query('month', Carbon::now()->month);
        $year  = (int) $request->query('year', Carbon::now()->year);
        $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;

        $query = Employee::with(['user', 'shift', 'position']);

        $role = strtolower($user->role);
        $position = strtolower($user->employee->position->name ?? '');

        $isSuperAdmin = in_array($role, ['superadmin', 'director'])
            || str_contains($position, 'sdi')
            || str_contains($position, 'sumber daya insani');

        if ($isSuperAdmin) {
            // Superadmin / Director / SDI: tampilkan semua karyawan
        } elseif ($role === 'admin') {
            // Admin: tampilkan hanya karyawan yang ada di approval_lines milik admin
            // Guard: exclude superadmin/director agar tidak muncul sebagai bawahan siapapun
            // Catatan: kasie (admin) bisa jadi bawahan kabag (admin) → tidak bisa filter by role='user'
            $query->where(function ($q) use ($user) {
                    $q->whereIn('user_id', function ($subQuery) use ($user) {
                            $subQuery->select('user_id')
                                ->from('approval_lines')
                                ->where('approver_id', $user->id);
                        })
                        ->orWhere('user_id', $user->id);
                })
                ->whereHas('user', function ($q) {
                    // Exclude superadmin & director agar tidak tampil sebagai bawahan admin
                    $q->whereNotIn('role', ['superadmin', 'director']);
                });
        } else {
            // User biasa: hanya tampilkan jadwal diri sendiri (read-only)
            $query->where('user_id', $user->id);
        }

        $limit = $request->query('limit', 10);
        $search = $request->query('search', '');

        if (!empty($search)) {
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'ilike', '%' . $search . '%');
            });
        }

        $paginatedEmployees = $query->paginate($limit);
        $employees = $paginatedEmployees->items();

        if (empty($employees)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'employees' => [
                        'data' => [],
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1
                    ]
                ],
                'message' => 'Tidak ada karyawan yang tersedia untuk Anda.'
            ]);
        }

        $schedules = ShiftSchedule::whereIn('user_id', collect($employees)->pluck('user_id'))
            ->where('month', $month)
            ->where('year', $year)
            ->get()
            ->keyBy('user_id');

        $dateStrings = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateStrings[$day] = Carbon::createFromDate($year, $month, $day)->format('Y-m-d');
        }

        $matrixData = [];

        foreach ($employees as $emp) {
            $row = [
                'user_id'   => $emp->user_id,
                'name'      => $emp->user?->name ?? 'Unknown',
                'position'  => $emp->position?->name ?? '-',
                'nip'       => $emp->nip,
                'work_scheme' => $emp->work_scheme ?? 'shift',
                'schedules' => []
            ];

            $userScheduleRow = $schedules->get($emp->user_id);
            $jsonSchedule = $userScheduleRow ? $userScheduleRow->schedule_data : [];

            if (is_string($jsonSchedule)) {
                $jsonSchedule = json_decode($jsonSchedule, true);
            }
            if (!is_array($jsonSchedule)) {
                $jsonSchedule = [];
            }

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateString = $dateStrings[$day];

                $dayData = isset($jsonSchedule[$day]) ? $jsonSchedule[$day] : null;

                if ($dayData) {
                    $row['schedules'][] = [
                        'date'     => $dateString,
                        'day'      => $day,
                        'type'     => 'custom',
                        'is_off'   => (bool) ($dayData['is_off'] ?? false),
                        'shift_id' => $dayData['shift_id'] ?? null,
                        'status'   => $userScheduleRow->status ?? 'draft'
                    ];
                } else {
                    $defaultShift = $emp->shift;
                    $row['schedules'][] = [
                        'date'     => $dateString,
                        'day'      => $day,
                        'type'     => 'default',
                        'is_off'   => false,
                        'shift_id' => $defaultShift ? $defaultShift->id : null,
                        'status'   => 'auto'
                    ];
                }
            }
            $matrixData[] = $row;
        }

        $masterShifts = Shift::select('id', 'name', 'start_time', 'end_time')
            ->whereRaw('LOWER(name) != ?', ['dayoff'])
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'year' => $year,
                'user_role' => $user->role,
                'employees' => [
                    'data' => $matrixData,
                    'total' => $paginatedEmployees->total(),
                    'current_page' => $paginatedEmployees->currentPage(),
                    'last_page' => $paginatedEmployees->lastPage()
                ],
                'master_shifts' => $masterShifts
            ]
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        // Guard: user biasa tidak boleh edit jadwal (read-only)
        $role     = strtolower($user->role);
        $position = strtolower($user->employee->position->name ?? '');
        $canEdit  = in_array($role, ['superadmin', 'admin', 'director'])
            || str_contains($position, 'sdi')
            || str_contains($position, 'sumber daya insani');

        if (!$canEdit) {
            return $this->respondError('Anda tidak memiliki akses untuk mengubah jadwal shift.', 403);
        }

        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer',
            'schedules' => 'required|array',
        ]);

        try {
            DB::beginTransaction();

            $month = $request->month;
            $year = $request->year;

            foreach ($request->schedules as $userUpdate) {
                $userId = $userUpdate['user_id'];
                $changes = $userUpdate['changes'];

                if ($user->role !== 'superadmin') {
                    $hasAccess = Employee::whereHas('user', function ($q) use ($userId) {
                        $q->where('user_id', $userId)
                            ->whereIn('user_id', function ($subQuery) {
                                $subQuery->select('user_id')
                                    ->from('approval_lines')
                                    ->where('approver_id', Auth::id());
                            });
                    })->exists();

                    if (!$hasAccess) {
                        DB::rollBack();
                        return $this->respondError('Anda tidak memiliki akses untuk mengubah jadwal karyawan tersebut', 403);
                    }
                }

                $scheduleRow = ShiftSchedule::firstOrNew([
                    'user_id' => $userId,
                    'month' => $month,
                    'year' => $year
                ]);

                $currentData = $scheduleRow->schedule_data ?? [];

                foreach ($changes as $change) {
                    $dayKey = (string) $change['day'];

                    $currentData[$dayKey] = [
                        'is_off' => $change['is_off'],
                        'shift_id' => $change['is_off'] ? null : ($change['shift_id'] ?? null)
                    ];
                }

                $scheduleRow->schedule_data = $currentData;
                $scheduleRow->status = 'draft';
                $scheduleRow->created_by = Auth::id();
                $scheduleRow->save();
            }

            DB::commit();
            return $this->respondSuccess(null, 'Jadwal berhasil disimpan.');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->respondError('Gagal menyimpan jadwal: ' . $th->getMessage());
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file'  => 'required|mimes:xlsx,xls,csv',
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer',
        ]);

        try {
            $import = new ShiftScheduleImport($request->month, $request->year);
            Excel::import($import, $request->file('file'));

            // Cek hasil SETELAH import selesai
            if ($import->processedCount === 0) {
                return $this->respondError('NIP di Excel tidak ada yang cocok dengan NIP di database.', 422);
            }

            $message = "Jadwal berhasil diimport. {$import->processedCount} karyawan diperbarui.";

            return $this->respondSuccess([
                'processed' => $import->processedCount,
            ], $message);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            return $this->respondError('Gagal validasi Excel: ' . $e->getMessage(), 422);
        } catch (\Throwable $th) {
            return $this->respondError('Gagal import jadwal: ' . $th->getMessage());
        }
    }




    public function downloadTemplate(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer',
        ]);

        $month = $request->month;
        $year = $request->year;

        $monthName = Carbon::createFromDate($year, $month, 1)->format('F');
        $fileName = "Jadwal_Civitas_{$monthName}_{$year}.xlsx";

        return Excel::download(new ShiftScheduleTemplateExport($month, $year), $fileName);
    }

    public function customExport(Request $request)
    {
        // 1. Validasi Input JSON dari React
        $request->validate([
            'start_date'   => 'required|date',
            'end_date'     => 'required|date|after_or_equal:start_date',
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'integer|exists:users,id'
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // 2. Buat Nama File yang Dinamis
        $fileName = "Jadwal_Custom_" . $startDate->format('d-M-Y') . "_sd_" . $endDate->format('d-M-Y') . ".xlsx";

        // 3. Panggil class Export yang baru
        return Excel::download(new ShiftScheduleTemplateExport(
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $request->employee_ids
        ), $fileName);
    }
}
