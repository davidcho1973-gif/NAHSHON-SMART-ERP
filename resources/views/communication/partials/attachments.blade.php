{{--
    메시지에 붙은 파일. 사진은 그대로 펼쳐 보이고(현장에서는 사진이 곧 내용이다),
    나머지는 카드로 보여준다. 파일이 저장소에서 사라졌더라도 이름은 남겨 둔다 —
    "그때 무엇을 올렸는지" 자체가 기록이기 때문이다.
--}}
@php($files = $message->relationLoaded('files') ? $message->files : $message->files()->get())

@if($files->isNotEmpty())
    <div class="attachments">
        @foreach($files as $file)
            @php($url = ($file->disk && $file->path) ? route('communication.file', ['room' => $room, 'file' => $file]) : null)
            @if($file->isImage() && $url)
                <a href="{{ $url }}" target="_blank" rel="noopener">
                    <img src="{{ $url }}" alt="{{ $file->original_name }}" loading="lazy">
                </a>
            @else
                <a class="file-card" @if($url) href="{{ $url }}" target="_blank" rel="noopener" @endif>
                    <span class="file-name">📎 {{ $file->original_name }}</span>
                    <span class="file-size">{{ $file->humanSize() }}{{ $url ? '' : ' · 원본 없음' }}</span>
                </a>
            @endif
        @endforeach
    </div>
@endif
