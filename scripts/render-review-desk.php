<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->instance('request', Illuminate\Http\Request::create('http://127.0.0.1:8767/document-hub?embed=1'));
$html = view('document-intelligence.index', [
    'companies' => collect([1=>'NAHSHON MEP']), 'sites'=>collect([1=>'703K']),
    'projects'=>collect([1=>'703K-KITCHEN']), 'canManage'=>true,
    'maxUploadMb'=>50,'maxUploadBytes'=>52428800,
    'defaultCompanyId'=>1,'defaultSiteId'=>null,'defaultProjectId'=>null,
])->render();
file_put_contents(__DIR__.'/../storage/app/review-desk-preview.html', $html);
echo "Rendered review desk fixture.\n";
