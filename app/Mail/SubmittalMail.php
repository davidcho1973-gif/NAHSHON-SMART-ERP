<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

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
        // In-Reply-To 는 <b>직전 한 통</b>을 가리키는 값이고, References 는 실타래 전체다.
        // 라라벨 Headers 에 전용 인자가 없어서 빠뜨리기 쉬운데, 대부분의 메일 클라이언트가
        // 회신을 묶을 때 먼저 보는 것이 이쪽이다. 이게 없으면 업체 받은편지함에서
        // 우리 요청과 재요청이 한 덩어리로 안 묶이고 낱장으로 흩어진다.
        // end() 는 배열을 참조로 받아 내부 포인터를 옮기는데, readonly 속성에는 그럴 수 없다
        // ("Cannot indirectly modify readonly property"). 그래서 마지막 키로 직접 집는다.
        $parent = $this->references === []
            ? null
            : $this->references[array_key_last($this->references)];

        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: $parent === null ? [] : ['In-Reply-To' => '<'.trim($parent, '<>').'>'],
        );
    }

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
