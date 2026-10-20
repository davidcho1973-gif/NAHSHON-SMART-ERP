<?php

return [
    'disk' => env('CAD_STORAGE_DISK'), // Falls back to the durable document storage disk.
    'max_bytes' => 20 * 1024 * 1024,
    'converter_binary' => env('CAD_CONVERTER_BINARY', base_path('services/cad-converter/publish/Nahshon.CadConverter')),
    'dotnet_binary' => env('CAD_DOTNET_BINARY', 'dotnet'),
    'converter_timeout' => 60,
];
