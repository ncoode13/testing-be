<?php

namespace App\Imports;

use App\Models\User;
use App\Models\Personal;
use App\Models\EmergencyContact;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Position;
use App\Models\JobLevel;
use App\Models\Shift;
use App\Models\EmploymentStatus;
use App\Models\Relationship;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmployeeImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            // Jika baris kosong (misal karena sisa format excel)
            if (empty($row['email']) || empty($row['first_name'])) {
                continue;
            }

            DB::transaction(function () use ($row) {
                // Auto-fill placeholders (Opsi 1)
                $postalCode = !empty($row['postal_code']) ? $row['postal_code'] : '00000';
                $address = !empty($row['address']) ? $row['address'] : '-';
                
                $emergencyName = !empty($row['emergency_contact_name']) ? $row['emergency_contact_name'] : '-';
                $emergencyPhone = !empty($row['emergency_contact_phone']) ? $row['emergency_contact_phone'] : '000000000';
                $emergencyRelStr = !empty($row['emergency_contact_relationship']) ? $row['emergency_contact_relationship'] : 'Lainnya';

                // Lookups untuk master data (Otomatis Buat Jika Tidak Ada)
                $dept = Department::firstOrCreate(['name' => $row['department'] ?? 'Umum']);
                $pos = Position::firstOrCreate(['name' => $row['position'] ?? 'Staff']);
                $jobLvl = JobLevel::firstOrCreate(['name' => $row['job_level'] ?? 'Level 1']);
                $shift = Shift::firstOrCreate(
                    ['name' => $row['shift'] ?? 'Shift Pagi'],
                    ['start_time' => '08:00:00', 'end_time' => '16:00:00'] // Default shift times
                );
                $empStatus = EmploymentStatus::firstOrCreate(['name' => $row['employment_status'] ?? 'Tetap']);
                $rel = Relationship::firstOrCreate(['name' => $emergencyRelStr]);

                // 1. Buat User
                $user = clone User::firstOrCreate(
                    ['email' => $row['email']],
                    [
                        'name' => $row['first_name'] . ' ' . $row['last_name'],
                        'username' => Str::slug($row['first_name'] . $row['last_name']) . rand(100, 999),
                        'password' => Hash::make('user1234'),
                        'role' => 'user',
                    ]
                );

                // 2. Buat Personal Info
                // Handle format tanggal (Excel biasanya memberikan angka serial jika format cell adalah Date)
                $birthDate = $this->parseDate($row['birth_date'] ?? '1990-01-01');

                Personal::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'place_of_birth' => $row['place_of_birth'] ?? '-',
                        'birth_date' => $birthDate,
                        'gender' => $row['gender'] ?? 'Laki-laki',
                        'marital_status' => $row['marital_status'] ?? 'Belum Kawin',
                        'blood_type' => $row['blood_type'] ?? 'O',
                        'religion' => $row['religion'] ?? 'Islam',
                        'phone' => $row['phone'] ?? '000000000',
                        'nik' => $row['nik'] ?? '0000000000000000',
                        'npwp' => $row['npwp'] ?? '000000000000000',
                        'postal_code' => $postalCode,
                        'address' => $address,
                    ]
                );

                // 3. Buat Emergency Contact
                $personalRecord = Personal::where('user_id', $user->id)->first();
                
                EmergencyContact::updateOrCreate(
                    ['personal_id' => $personalRecord->id],
                    [
                        'name' => $emergencyName,
                        'phone' => $emergencyPhone,
                        'relationship_id' => $rel->id,
                    ]
                );

                // 4. Buat Employee
                $joinDate = $this->parseDate($row['join_date'] ?? date('Y-m-d'));
                $endDate = !empty($row['end_date']) ? $this->parseDate($row['end_date']) : null;

                $isPpaStr = strtolower((string)($row['is_ppa'] ?? '0'));
                $isPpa = in_array($isPpaStr, ['1', 'ya', 'yes', 'true', 'y']) ? 1 : 0;

                Employee::updateOrCreate(
                    ['nip' => $row['nip'] ?? ('NIP' . rand(1000, 9999))],
                    [
                        'user_id' => $user->id,
                        'employee_id' => $row['nip'] ?? ('NIP' . rand(1000, 9999)), // Asumsi nip == employee_id
                        'department_id' => $dept->id,
                        'position_id' => $pos->id,
                        'job_level_id' => $jobLvl->id,
                        'shift_id' => $shift->id,
                        'employment_status_id' => $empStatus->id,
                        'work_scheme' => $row['work_scheme'] ?? 'shift',
                        'join_date' => $joinDate,
                        'end_date' => $endDate,
                        'is_ppa' => $isPpa,
                        'group' => $row['group'] ?? '-',
                        'rank' => $row['rank'] ?? '-',
                    ]
                );
            });
        }
    }

    private function parseDate($value)
    {
        if (is_numeric($value)) {
            // Excel serial date format
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }
        
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return '1990-01-01'; // Default fallback
        }
    }
}
