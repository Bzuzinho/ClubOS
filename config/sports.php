<?php

return [
    'club_id' => env('SPORTS_CLUB_ID', 'bscn'),

    'scheduling' => [
        'lane_overlap_policy' => env('SPORTS_LANE_OVERLAP_POLICY', 'warn'),
        'athlete_overlap_policy' => env('SPORTS_ATHLETE_OVERLAP_POLICY', 'warn'),
        'capacity_policy' => env('SPORTS_CAPACITY_POLICY', 'warn'),
    ],
];
