<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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
    ) {}

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
