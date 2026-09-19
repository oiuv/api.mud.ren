<?php

namespace App\Services;

use App\Content;
use App\Thread;

class ThreadSearch
{
    public function search(string $term)
    {
        $query = Thread::published()->with('content');

        if ($term === '') {
            $query->whereRaw('1 = 0');
        } else {
            $htmlThreadIds = $this->matchingHtmlThreads($term);
            // INSTR treats every character literally, including LIKE's wildcard/escape characters.
            $query->where(function ($query) use ($term, $htmlThreadIds) {
                $query->whereRaw('INSTR(LOWER(title), LOWER(?)) > 0', [$term])
                    ->orWhereHas('content', function ($query) use ($term) {
                        $query->whereRaw('INSTR(LOWER(markdown), LOWER(?)) > 0', [$term]);
                    })
                    ->orWhereIn('id', $htmlThreadIds);
            })->orderByRaw('CASE WHEN INSTR(LOWER(title), LOWER(?)) > 0 THEN 0 ELSE 1 END', [$term]);
        }

        $threads = $query->orderByDesc('published_at')->orderByDesc('id')->paginate(10);
        foreach ($threads as $thread) {
            $body = $this->searchableBody($thread->content);
            $thread->setAttribute('highlights', [
                // Always provide an escaped title: consumers render this field as HTML.
                'title' => [$this->highlight($thread->title, $term)],
                'content' => $body === '' ? [] : [$this->highlight($this->snippet($body, $term), $term)],
            ]);
        }

        return $threads;
    }

    private function matchingHtmlThreads(string $term): array
    {
        $ids = [];
        // MySQL 5.7 cannot strip HTML and decode arbitrary entities. Normalize only
        // public HTML-only bodies, then let SQL count and paginate all matching IDs.
        Content::query()->select(['id', 'contentable_id', 'markdown', 'body'])
            ->where(function ($query) {
                $query->whereNull('markdown')->orWhereRaw('LENGTH(markdown) = 0');
            })
            ->whereHasMorph('contentable', [Thread::class], function ($query) {
                $query->published();
            })
            ->chunkById(100, function ($contents) use ($term, &$ids) {
                foreach ($contents as $content) {
                    if (mb_stripos($this->searchableBody($content), $term, 0, 'UTF-8') !== false) {
                        $ids[] = $content->contentable_id;
                    }
                }
            });

        return $ids;
    }

    private function searchableBody(?Content $content): string
    {
        if ($content === null) {
            return '';
        }
        if ($content->markdown !== null && $content->markdown !== '') {
            return $content->markdown;
        }

        return html_entity_decode(strip_tags((string) $content->body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function snippet(string $text, string $term): string
    {
        $position = mb_stripos($text, $term, 0, 'UTF-8');
        $start = max(0, ($position === false ? 0 : $position) - 60);
        $snippet = mb_substr($text, $start, 200, 'UTF-8');

        return ($start > 0 ? '…' : '').$snippet.(mb_strlen($text, 'UTF-8') > $start + 200 ? '…' : '');
    }

    private function highlight(string $text, string $term): string
    {
        $parts = preg_split('/('.preg_quote($term, '/').')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            $part = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($index % 2 === 1) {
                $part = '<em>'.$part.'</em>';
            }
        }

        return implode('', $parts);
    }
}
