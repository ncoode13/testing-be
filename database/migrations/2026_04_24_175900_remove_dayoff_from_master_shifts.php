<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hapus shift 'Dayoff' dari master shifts.
     * Konversi semua referensi shift_id yang mengarah ke 'Dayoff' menjadi is_off: true.
     */
    public function up(): void
    {
        $dayoffShift = DB::table('shifts')
            ->whereRaw('LOWER(name) = ?', ['dayoff'])
            ->first();

        if (!$dayoffShift) {
            return; // Sudah tidak ada, aman dilanjutkan
        }

        // 1. Update karyawan yang memiliki shift_id = dayoff → set null
        DB::table('employees')
            ->where('shift_id', $dayoffShift->id)
            ->update(['shift_id' => null]);

        // 2. Konversi shift_schedules: entri yang pakai shift_id dayoff → is_off: true
        $schedules = DB::table('shift_schedules')->get();

        foreach ($schedules as $schedule) {
            $data = json_decode($schedule->schedule_data, true);

            if (!is_array($data)) {
                continue;
            }

            $changed = false;

            foreach ($data as $day => $entry) {
                if (isset($entry['shift_id']) && $entry['shift_id'] == $dayoffShift->id) {
                    $data[$day]['is_off']   = true;
                    $data[$day]['shift_id'] = null;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('shift_schedules')
                    ->where('id', $schedule->id)
                    ->update(['schedule_data' => json_encode($data)]);
            }
        }

        // 3. Hapus shift Dayoff dari master shifts
        DB::table('shifts')->where('id', $dayoffShift->id)->delete();
    }

    /**
     * Rollback: buat ulang shift Dayoff (tanpa mengembalikan referensi lama).
     */
    public function down(): void
    {
        DB::table('shifts')->insert([
            'name'                    => 'Dayoff',
            'start_time'              => '00:00:00',
            'end_time'                => '00:00:00',
            'tolerance_come_too_late' => 0,
            'tolerance_go_home_early' => 0,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }
};
