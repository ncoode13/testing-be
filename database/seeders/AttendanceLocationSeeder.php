<?php

namespace Database\Seeders;

use App\Models\AttendanceLocation;
use Illuminate\Database\Seeder;

class AttendanceLocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        AttendanceLocation::create([
            'name'      => 'Titik A',
            'address'   => 'Gang Cempaka Sebelas RT.2/12, Jl. Sampangan No.119, Semanggi, Kec. Ps. Kliwon, Kota Surakarta',
            'latitude'  => '-7.581378051341827',
            'longitude' => '110.83655807826169',
            'radius'    => '12',
        ]);

        AttendanceLocation::create([
            'name'      => 'Titik B',
            'address'   => 'Gg. Cempaka 10 No.22, Semanggi, Kec. Ps. Kliwon, Kota Surakarta',
            'latitude'  => '-7.581741237617778',
            'longitude' => '110.83669606982903',
            'radius'    => '20',
        ]);

        AttendanceLocation::create([
            'name'      => 'Titik C',
            'address'   => 'Jl. Wiropaten, Semanggi, Kec. Ps. Kliwon, Kota Surakarta',
            'latitude'  => '-7.581690061578696',
            'longitude' => '110.83656133495545',
            'radius'    => '16',
        ]);

        AttendanceLocation::create([
            'name'      => 'Titik D',
            'address'   => 'Semanggi, Kec. Ps. Kliwon, Kota Surakarta (Area Barat)',
            'latitude'  => '-7.58166135306629',
            'longitude' => '110.83642030405973',
            'radius'    => '20',
        ]);

        AttendanceLocation::create([
            'name'      => 'Titik E',
            'address'   => 'Semanggi, Kec. Ps. Kliwon, Kota Surakarta (Area Parkir/Belakang)',
            'latitude'  => '-7.581628899963021',
            'longitude' => '110.83628934679945',
            'radius'    => '20',
        ]);
    }
}
