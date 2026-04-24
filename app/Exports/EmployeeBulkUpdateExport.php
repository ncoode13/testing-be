<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Extend DefaultValueBinder dan implement WithCustomValueBinder
class EmployeeBulkUpdateExport extends DefaultValueBinder implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting, WithCustomValueBinder
{
    protected $employeeIds;
    protected $category;

    // Mapping untuk gender
    private $genderMap = [
        'male' => 'Laki-laki',
        'Male' => 'Laki-laki',
        'female' => 'Perempuan',
        'Female' => 'Perempuan',
    ];

    // Mapping untuk religion
    private $religionMap = [
        'islam' => 'Islam',
        'Islam' => 'Islam',
        'kristen' => 'Kristen',
        'Kristen' => 'Kristen',
        'katholik' => 'Katolik',
        'Katholik' => 'Katolik',
        'budha' => 'Buddha',
        'Budha' => 'Buddha',
        'hindu' => 'Hindu',
        'Hindu' => 'Hindu',
        'konghucu' => 'Konghujuu',
        'Konghucu' => 'Konghucu',
    ];

    // Mapping untuk work_scheme
    private $workSchemeMap = [
        'office' => 'Kantor',
        'shift' => 'Shift',
    ];

    // Mapping untuk marital status
    private $maritalStatusMap = [
        'single' => 'Belum Menikah',
        'Single' => 'Belum Menikah',
        'married' => 'Menikah',
        'Married' => 'Menikah',
        'divorced' => 'Cerai',
        'Divorced' => 'Cerai',
        'widower' => 'Duda',
        'Widower' => 'Duda',
        'widow' => 'Janda',
        'Widow' => 'Janda',
    ];

    public function __construct($employeeIds, $category)
    {
        $this->employeeIds = $employeeIds;
        $this->category = $category;
    }

    public function collection()
    {
        return Employee::with([
            'user.personal.emergencyContact.relationship',
            'user.personal.maritalStatus',
            'department',
            'position',
            'job_level',
            'employment_status'
        ])
            ->whereIn('id', $this->employeeIds)
            ->get();
    }

    public function headings(): array
    {
        if ($this->category === 'general') {
            return [
                'NIP',
                'Nama Lengkap',                   // Gabungan First Name + Last Name
                'Email',
                'NIK (16 Digit)',
                'Alamat Lengkap',                 // Asalnya: Address
                'Tempat Lahir',                   // Asalnya: Place of Birth
                'Tanggal Lahir (YYYY-MM-DD)',     // Asalnya: Date of Birth
                'Nomor Telepon',                  // Asalnya: Phone Number
                'Jenis Kelamin',                  // Asalnya: Gender
                'Status Pernikahan',              // Asalnya: Marital Status
                'Agama',                          // Asalnya: Religion
                'Departemen / Organisasi',        // Asalnya: Organization Name
                'Posisi Pekerjaan',               // Asalnya: Job Position
                'Level Pekerjaan',                // Asalnya: Job Level
                'Golongan',                         // Asalnya: Group
                'Pangkat',                          // Asalnya: Class
                'Status Kepegawaian',             // Asalnya: Employment Status
                'Tanggal Bergabung (YYYY-MM-DD)', // Asalnya: Join Date
                'Tanggal Berakhir (YYYY-MM-DD)',  // Asalnya: End Date
                'NPWP',
                'Masa Kerja'                      // Asalnya: Length of Service
            ];
        }

        // Untuk kategori 'emergency'
        return [
            'NIP',
            'Nama Karyawan',                      // Asalnya: Employee Name
            'Nama Kontak Darurat',                // Asalnya: Contact Name
            'Hubungan (Keluarga/Kerabat)',        // Asalnya: Relationship
            'Nomor Telepon Kontak'                // Asalnya: Contact Phone
        ];
    }

    public function columnFormats(): array
    {
        if ($this->category === 'general') {
            return [
                'A' => NumberFormat::FORMAT_TEXT, // NIP
                'D' => NumberFormat::FORMAT_TEXT, // NIK
                'T' => NumberFormat::FORMAT_TEXT, // NPWP
            ];
        }

        return [
            'A' => NumberFormat::FORMAT_TEXT,
        ];
    }

    public function bindValue(Cell $cell, $value)
    {
        $column = $cell->getColumn();

        // 1. Kolom A (NIP) ada di kategori General maupun Emergency
        // Paksa Excel membacanya sebagai String/Teks murni
        if ($column === 'A') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            return true;
        }

        // 2. Kolom D (NIK) dan T (NPWP) khusus di kategori General
        if ($this->category === 'general' && in_array($column, ['D', 'T'])) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            return true;
        }

        // Untuk kolom lain, biarkan Excel yang menentukan
        return parent::bindValue($cell, $value);
    }

    /**
     * Translate value ke bahasa Indonesia
     */
    private function translateValue($key, $value)
    {
        if ($key === 'gender') {
            return $this->genderMap[$value] ?? $value;
        }
        if ($key === 'religion') {
            return $this->religionMap[$value] ?? $value;
        }
        if ($key === 'work_scheme') {
            return $this->workSchemeMap[$value] ?? $value;
        }
        if ($key === 'marital_status') {
            return $this->maritalStatusMap[$value] ?? $value;
        }
        return $value;
    }

    public function map($employee): array
    {
        $personal = $employee->user->personal ?? null;

        if ($this->category === 'general') {
            $los = '-';
            if ($employee->join_date) {
                $los = Carbon::parse($employee->join_date)->diff(now())->format('%y Tahun, %m Bulan');
            }

            return [
                $employee->nip,
                trim(($personal->first_name ?? '') . ' ' . ($personal->last_name ?? '')) ?: '-',
                $employee->user->email ?? '-',
                // Pastikan variabel dilempar sebagai string murni untuk amannya
                (string) ($personal->nik ?? '-'),
                $personal->address ?? '-',
                $personal->place_of_birth ?? '-',
                $personal->birth_date ? Carbon::parse($personal->birth_date)->format('Y-m-d') : '-',
                $personal->phone ?? '-',
                $this->translateValue('gender', $personal->gender ?? '-'),
                $this->translateValue('marital_status', $personal->maritalStatus->name ?? $personal->marital_status ?? '-'),
                $this->translateValue('religion', $personal->religion ?? '-'),
                $employee->department->name ?? '-',
                $employee->position->name ?? '-',
                $employee->job_level->name ?? '-',
                $employee->group ?? '-',
                $employee->rank ?? '-',
                $employee->employment_status->name ?? '-',
                $employee->join_date ? Carbon::parse($employee->join_date)->format('Y-m-d') : '-',
                $employee->end_date ? Carbon::parse($employee->end_date)->format('Y-m-d') : '-',
                // Sama, di-cast ke string
                (string) ($personal->npwp ?? '-'),
                $los
            ];
        }

        $emergency = $personal ? $personal->emergencyContact : null;
        return [
            $employee->nip,
            $employee->user->name ?? '-',
            $emergency->name ?? '-',
            $emergency->relationship->name ?? '-',
            $emergency->phone ?? '-'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Header style
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF0284C7'],
            ],
        ];

        $styles = [
            1 => $headerStyle,
        ];

        // Protect sheet supaya NIP column tidak bisa diedit
        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setPassword('');

        // Set kolom A (NIP) tidak bisa diedit
        $sheet->getStyle('A:A')->getProtection()->setLocked(true);

        // Unlock columns lain agar bisa diedit
        if ($this->category === 'general') {
            $sheet->getStyle('B:U')->getProtection()->setLocked(false);
        } else {
            $sheet->getStyle('B:E')->getProtection()->setLocked(false);
        }

        return $styles;
    }
}
