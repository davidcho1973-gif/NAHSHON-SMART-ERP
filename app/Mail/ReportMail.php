<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * 정기 보고 메일 — 일일 작업계획서·마감보고서가 같은 봉투를 쓴다.
 *
 * 본문은 서비스(DailyReportComposer)가 조립해 넘긴다. 원청은 첨부를 잘 열지
 * 않으므로 <b>본문 자체가 보고서</b>이고, 첨부는 사진과 인쇄용 사본이다.
 */
class ReportMail extends Mailable
{
    /**
     * @param  list<array{data: string, name: string, mime: string}>  $files
     * @param  list<string>  $ccList
     */
    public function __construct(
        public readonly string $subjectLine,
        // Mailable 부모가 $html 을 이미 갖고 있어 다른 이름을 쓴다.
        public readonly string $bodyHtml,
        public readonly array $files = [],
        public readonly array $ccList = [],
        /** 우리가 발급한 Message-ID. 회신을 되짚는 열쇠라 발송하는 쪽이 정한다. */
        public readonly ?string $messageId = null,
        /** @var list<string> 같은 실타래의 앞선 Message-ID 들 */
        public readonly array $references = [],
    ) {}

/**
     * 우리가 발급한 Message-ID 를 봉투에 박는다.
     *
     * 이게 없으면 상대가 답장을 눌러도 그 회신이 <b>어느 서신의 답인지</b> 알 방법이 없다.
     * 메일 클라이언트는 회신에 In-Reply-To / References 로 이 값을 그대로 되돌려 주므로,
     * 이 한 줄이 2단계(수신)에서 회신을 실타래에 꽂는 유일한 열쇠가 된다.
     *
     * 라라벨이 스스로 만드는 Message-ID 는 우리가 알 수 없어서 기록할 수도, 되짚을 수도 없다.
     */
    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, cc: $this->ccList);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->bodyHtml);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $f): Attachment => Attachment::fromData(fn (): string => $f['data'], $f['name'])
                ->withMime($f['mime']),
            $this->files,
        );
    }
}
