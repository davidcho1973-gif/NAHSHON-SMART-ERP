<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 제출물 소통 메일 — 업체 자료 요청과 원청 전달이 같은 봉투를 쓴다.
 *
 * 본문 HTML 은 서비스가 만들어 넣는다(조항·요구·첨부 목록). Mailable 은
 * 봉투와 첨부만 책임진다 — 내용 규칙이 두 곳에 흩어지지 않게.
 */
class SubmittalMail extends Mailable
{
    /**
     * @param  list<array{data: string, name: string, mime: string}>  $files
     */
    public function __construct(
        public readonly string $subjectLine,
        // Mailable 부모가 $html 을 이미 갖고 있어 다른 이름을 쓴다.
        public readonly string $bodyHtml,
        public readonly array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
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
