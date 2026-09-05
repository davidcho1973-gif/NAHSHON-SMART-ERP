<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * 영수증 파일을 받을 수 있는가 — 세 화면(경비 마법사·영수증 앱·ERP 스캔)이 같은 답을 낸다.
 *
 * ── 왜 따로 두는가 ─────────────────────────────────────────────────────
 * 화면마다 «image|max:10240» 를 각자 적어 두었더니 세 가지가 어긋났다.
 *
 *  1. 화면은 PDF 를 고르게 해 두고(accept="application/pdf") 서버는 image 만 받았다.
 *     이메일로 온 영수증은 거의 PDF 다.
 *  2. 아이폰 사진(HEIC)은 Laravel 의 image 규칙에 없다. 폰에서 찍은 것을 그대로 올리면
 *     막힌다 — 판독기(Gemini)는 HEIC 를 읽는데도.
 *  3. 걸렸을 때 화면이 JSON 을 기다리는데 서버는 «다시 입력하세요» 로 <b>리다이렉트</b>했다.
 *     화면에는 «Unexpected token '<' … is not valid JSON» 만 떴다. 무엇이 문제인지
 *     아무도 알 수 없는 오류다 — 2026-09-03 나손에서 실제로 났다.
 *
 * 그래서 규칙을 한 곳에 두고, 못 받을 때는 «왜» 를 사람 말로 돌려준다. 리다이렉트는 없다.
 *
 * ── 크기 ────────────────────────────────────────────────────────────────
 * 한도는 PHP 가 실제로 받아 주는 크기(public/.user.ini 의 upload_max_filesize=64M)에
 * 맞춘다. 판독에 넘기기 전에 서버가 어차피 줄이므로(ImageDownscale) 큰 원본을 막을 이유가
 * 없다 — 막으면 사람이 폰에서 사진을 줄여 다시 올려야 하는데, 그건 앱이 할 일이다.
 */
final class ReceiptUpload
{
    /** 받는 형식 — 판독기가 읽을 수 있는 것만. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'heic', 'heif', 'pdf'];

    /** KB. public/.user.ini 의 upload_max_filesize 와 같다. */
    public const MAX_KB = 64 * 1024;

    public const CODE_MISSING = 'receipt_missing';

    public const CODE_TOO_LARGE = 'receipt_too_large';

    public const CODE_BAD_TYPE = 'receipt_bad_type';

    /** 서버가 만드는 메시지는 서버가 언어까지 책임진다 — 작업자앱과 같은 원칙. */
    public const MESSAGES = [
        self::CODE_MISSING => [
            'ko' => '영수증 파일이 없습니다. 사진을 찍거나 파일을 골라 주세요.',
            'en' => 'No receipt file. Take a photo or choose a file.',
            'es' => 'No hay archivo de recibo. Tome una foto o elija un archivo.',
        ],
        self::CODE_TOO_LARGE => [
            'ko' => '파일이 너무 큽니다 (최대 64MB). 사진을 다시 찍거나 더 작은 파일로 올려 주세요.',
            'en' => 'File is too large (max 64MB). Retake the photo or upload a smaller file.',
            'es' => 'El archivo es demasiado grande (máx. 64MB). Vuelva a tomar la foto o suba un archivo más pequeño.',
        ],
        self::CODE_BAD_TYPE => [
            'ko' => '지원하지 않는 파일 형식입니다. 사진(JPG·PNG·HEIC) 또는 PDF 만 올릴 수 있습니다.',
            'en' => 'Unsupported file type. Only photos (JPG, PNG, HEIC) or PDF are accepted.',
            'es' => 'Tipo de archivo no compatible. Solo se aceptan fotos (JPG, PNG, HEIC) o PDF.',
        ],
    ];

    public static function rule(bool $required = true): string
    {
        return ($required ? 'required' : 'nullable').'|file|mimes:'.implode(',', self::EXTENSIONS).'|max:'.self::MAX_KB;
    }

    /**
     * 이 요청의 영수증 파일을 받을 수 없으면 그 이유(코드)를, 받을 수 있으면 null 을 돌려준다.
     */
    public static function problem(Request $request, string $field = 'receipt', bool $required = true): ?string
    {
        $file = $request->file($field);

        if (! $file instanceof UploadedFile) {
            if (! $required) {
                return null;
            }

            // 파일이 아예 안 왔다. 한도(post_max_size)를 넘긴 요청은 PHP 가 본문을 통째로
            // 버려서 «파일 없음» 으로 보인다 — Content-Length 로 구별한다.
            return self::bodyTooLarge($request) ? self::CODE_TOO_LARGE : self::CODE_MISSING;
        }

        // upload_max_filesize 에 걸린 파일 — 이름만 오고 내용은 없다.
        if (! $file->isValid()) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? self::CODE_TOO_LARGE
                : self::CODE_MISSING;
        }

        $validator = Validator::make([$field => $file], [$field => self::rule($required)]);
        if ($validator->passes()) {
            return null;
        }

        $failed = array_keys($validator->failed()[$field] ?? []);
        if (in_array('Max', $failed, true)) {
            return self::CODE_TOO_LARGE;
        }
        if (in_array('Mimes', $failed, true) || in_array('File', $failed, true)) {
            return self::CODE_BAD_TYPE;
        }

        return self::CODE_MISSING;
    }

    public static function say(string $code, string $lang = 'ko'): string
    {
        $set = self::MESSAGES[$code] ?? self::MESSAGES[self::CODE_MISSING];

        return $set[$lang] ?? $set['ko'];
    }

    /** 사람이 보는 한 줄 — 화면의 «어떤 파일을 올릴 수 있나». */
    public static function hint(): string
    {
        return 'JPG · PNG · HEIC · PDF, 최대 64MB';
    }

    /** 한도 판정은 UploadLimits 한 곳에 있다 — 문서함 드롭존도 같은 숫자를 봐야 한다. */
    private static function bodyTooLarge(Request $request): bool
    {
        return UploadLimits::bodyOverflowed($request);
    }
}
