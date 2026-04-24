<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\Department;
use App\Models\Position;
use App\Models\JobLevel;
use App\Models\EmploymentStatus;
use App\Models\Relationship;
use App\Models\EmergencyContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmployeeBulkUpdateImport implements ToCollection, WithHeadingRow
{
    protected $category;

    protected $departments, $positions, $jobLevels, $employmentStatuses, $relationships;

    public function __construct($category)
    {
        $this->category = $category;

        $this->departments = Department::pluck('id', 'name')->mapWithKeys(fn($id, $name) => [strtolower(trim($name)) => $id]);
        $this->positions = Position::pluck('id', 'name')->mapWithKeys(fn($id, $name) => [strtolower(trim($name)) => $id]);
        $this->jobLevels = JobLevel::pluck('id', 'name')->mapWithKeys(fn($id, $name) => [strtolower(trim($name)) => $id]);
        $this->employmentStatuses = EmploymentStatus::pluck('id', 'name')->mapWithKeys(fn($id, $name) => [strtolower(trim($name)) => $id]);
        $this->relationships = Relationship::pluck('id', 'name')->mapWithKeys(fn($id, $name) => [strtolower(trim($name)) => $id]);
    }

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'File Excel kosong.']);
        }

        $firstRow = $rows->first()->toArray();

        // PENYESUAIAN VALIDASI HEADER
        if ($this->category === 'general') {
            if (!array_key_exists('nama_lengkap', $firstRow) || !array_key_exists('tempat_lahir', $firstRow)) {
                throw ValidationException::withMessages([
                    'file' => 'Format template salah! Anda memilih kategori "General Info", tetapi mengunggah template file yang berbeda.'
                ]);
            }
        } elseif ($this->category === 'emergency') {
            if (!array_key_exists('nama_kontak_darurat', $firstRow) || !array_key_exists('hubungan_keluargakerabat', $firstRow)) {
                throw ValidationException::withMessages([
                    'file' => 'Format template salah! Anda memilih kategori "Emergency Contact", tetapi mengunggah template file yang berbeda.'
                ]);
            }
        }

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $excelRowNumber = $index + 2;

                if (!isset($row['nip']) || empty(trim($row['nip']))) {
                    continue;
                }

                $nip = trim($row['nip']);
                $employee = Employee::with('user.personal.emergencyContact')->where('nip', $nip)->first();

                if (!$employee || !$employee->user) {
                    throw ValidationException::withMessages([
                        'file' => "Gagal di Baris ke-{$excelRowNumber}: NIP {$nip} tidak ditemukan di database."
                    ]);
                }

                if ($this->category === 'general') {
                    $this->updateGeneralInfo($employee, $row);
                } elseif ($this->category === 'emergency') {
                    $this->updateEmergencyContact($employee, $row);
                }
            }

            DB::commit();
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw ValidationException::withMessages([
                'file' => "Terjadi kesalahan sistem pada baris NIP: " . ($nip ?? 'Unknown') . ". Error: " . $e->getMessage()
            ]);
        }
    }

    private function updateGeneralInfo($employee, $row)
    {
        $user = $employee->user;
        $personal = $user->personal;

        // Pecah 'Nama Lengkap' menjadi first_name (kata pertama) dan last_name (sisa kata)
        $namaLengkap = trim($row['nama_lengkap'] ?? '');
        if ($namaLengkap && $namaLengkap !== '-') {
            $parts     = explode(' ', $namaLengkap, 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? '';
        } else {
            $firstName = $personal->first_name;
            $lastName  = $personal->last_name;
        }

        $user->update([
            'name'  => trim($firstName . ' ' . $lastName),
            'email' => $row['email'] ?? $user->email,
        ]);

        if ($personal) {
            $personal->update([
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'nik'            => $row['nik_16_digit'] ?? $personal->nik,
                'address'        => $row['alamat_lengkap'] ?? $personal->address,
                'place_of_birth' => $row['tempat_lahir'] ?? $personal->place_of_birth,
                'birth_date'     => $this->parseDate($row['tanggal_lahir_yyyy_mm_dd']) ?? $personal->birth_date,
                'phone'          => $row['nomor_telepon'] ?? $personal->phone,
                'gender'         => $row['jenis_kelamin'] ?? $personal->gender,
                'marital_status' => $row['status_pernikahan'] ?? $personal->marital_status,
                'religion'       => $row['agama'] ?? $personal->religion,
                'npwp'           => $row['npwp'] ?? $personal->npwp,
            ]);
        }

        $deptKey = strtolower(trim($row['departemen_organisasi'] ?? ''));
        $posKey  = strtolower(trim($row['posisi_pekerjaan'] ?? ''));
        $lvlKey  = strtolower(trim($row['level_pekerjaan'] ?? ''));
        $statKey = strtolower(trim($row['status_kepegawaian'] ?? ''));

        $employee->update([
            'department_id'        => $this->departments[$deptKey] ?? $employee->department_id,
            'position_id'          => $this->positions[$posKey] ?? $employee->position_id,
            'job_level_id'         => $this->jobLevels[$lvlKey] ?? $employee->job_level_id,
            'employment_status_id' => $this->employmentStatuses[$statKey] ?? $employee->employment_status_id,
            'group'                => $row['grade'] ?? $employee->group,
            'rank'                 => $row['kelas'] ?? $employee->rank,
            'join_date'            => $this->parseDate($row['tanggal_bergabung_yyyy_mm_dd']) ?? $employee->join_date,
            'end_date'             => $this->parseDate($row['tanggal_berakhir_yyyy_mm_dd']) ?? $employee->end_date,
        ]);
    }

    private function updateEmergencyContact($employee, $row)
    {
        $personal = $employee->user->personal;
        if (!$personal) return;

        // PENYESUAIAN KEY ARRAY BAHASA INDONESIA
        $relKey = strtolower(trim($row['hubungan_keluargakerabat'] ?? ''));
        $relationshipId = $this->relationships[$relKey] ?? null;

        EmergencyContact::updateOrCreate(
            ['personal_id' => $personal->id],
            [
                'name'            => $row['nama_kontak_darurat'] ?? '-',
                'phone'           => $row['nomor_telepon_kontak'] ?? '-',
                'relationship_id' => $relationshipId
            ]
        );
    }

    private function parseDate($value)
    {
        if (!$value || $value === '-') return null;

        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }
        return $value;
    }
}
