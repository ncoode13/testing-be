<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\EmployeeImport;

class EmployeeExcelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $filePath = database_path('seeders/data/template_karyawan.xlsx');
        
        if (!file_exists($filePath)) {
            $this->command->error("File tidak ditemukan: {$filePath}");
            return;
        }

        $this->command->info('Mulai import data karyawan dari Excel...');
        
        Excel::import(new EmployeeImport, $filePath);
        
        $this->command->info('Data karyawan berhasil di-import!');
    }
}
