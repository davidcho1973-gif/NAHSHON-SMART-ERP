<?php

use App\Support\DurableDisk;

return [
    'disk' => DurableDisk::resolve(env('MEETING_DISK'), 'local'),
    'max_bytes' => 64 * 1024 * 1024,
    'chunk_bytes' => 1024 * 1024,
    'max_seconds' => 1800,
    'gemini_model' => env('MEETING_TRANSCRIBE_MODEL', 'gemini-3.5-transcribe'),
    'analysis_model' => env('MEETING_ANALYSIS_MODEL', 'gemini-3.1-pro-preview'),
    'elevenlabs_key' => env('ELEVENLABS_API_KEY'),
    'scribe_model' => 'scribe_v2',
    // Only verified internal requests and read-only lookups run automatically.
    'auto_internal_tasks' => (bool) env('MEETING_AUTO_INTERNAL_TASKS', true),
];
