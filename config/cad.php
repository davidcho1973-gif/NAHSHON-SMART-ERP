<?php

return [
    'disk' => env('CAD_STORAGE_DISK'), // Falls back to the durable document storage disk.
    // One bound for originals, editable DXF revisions and native conversion.
    'max_bytes' => 64 * 1024 * 1024,
    'converter_binary' => env('CAD_CONVERTER_BINARY', base_path('services/cad-converter/publish/Nahshon.CadConverter')),
    'dotnet_binary' => env('CAD_DOTNET_BINARY', 'dotnet'),
    'converter_timeout' => 60,
    // Actual heap is also limited by current container headroom. This is not a file-size bound.
    'converter_heap_mib' => env('CAD_CONVERTER_HEAP_MIB', 256),
    // Enable only after the dedicated cad:work cluster has passed its health check.
    'isolated_conversions' => env('CAD_ISOLATED_CONVERSIONS', false),
    'worker_heap_mib' => env('CAD_WORKER_HEAP_MIB', 3072),
    'worker_min_memory_mib' => 4096,
    'worker_timeout' => 600,
];
