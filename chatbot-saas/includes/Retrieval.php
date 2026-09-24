<?php
/**
 * Turns trained content into searchable chunks, and finds the most
 * relevant chunks for a visitor's question using SQLite's built-in FTS5
 * full-text index (BM25 ranking). No embeddings API, no vector database,
 * no extra per-query cost - a good fit for FAQ/support bots trained on a
 * few pages of business content.
 */

const CHUNK_TARGET_CHARS = 900;
const CHUNK_MAX_CHARS = 1400;

/** Split long plain text into overlap-free chunks on paragraph/sentence boundaries. */
function chunk_text(string $text): array
{
    $text = preg_replace("/\r\n?/", "\n", $text);
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $paragraphs = preg_split("/\n\s*\n/", trim($text));

    $chunks = [];
    $buffer = '';

    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') {
            continue;
        }

        if (strlen($para) > CHUNK_MAX_CHARS) {
            // Long paragraph: split on sentence boundaries.
            $sentences = preg_split('/(?<=[.!?])\s+/', $para);
            foreach ($sentences as $sentence) {
                if (strlen($buffer) + strlen($sentence) + 1 > CHUNK_TARGET_CHARS && $buffer !== '') {
                    $chunks[] = trim($buffer);
                    $buffer = '';
                }
                $buffer .= ($buffer === '' ? '' : ' ') . $sentence;
            }
            continue;
        }

        if (strlen($buffer) + strlen($para) + 2 > CHUNK_TARGET_CHARS && $buffer !== '') {
            $chunks[] = trim($buffer);
            $buffer = '';
        }
        $buffer .= ($buffer === '' ? '' : "\n\n") . $para;
    }

    if (trim($buffer) !== '') {
        $chunks[] = trim($buffer);
    }

    return array_values(array_filter($chunks, fn($c) => strlen($c) >= 20));
}

/** Chunk $text and store it against $sourceId, updating the source's status. */
function index_source(int $botId, int $sourceId, string $text): void
{
    $pdo = db();
    $chunks = chunk_text($text);

    $pdo->beginTransaction();
    try {
        // Wipe any previous chunks for this source (re-index case).
        $del = $pdo->prepare('DELETE FROM chunks WHERE source_id = ?');
        $del->execute([$sourceId]);

        $ins = $pdo->prepare('INSERT INTO chunks (bot_id, source_id, content, created_at) VALUES (?, ?, ?, ?)');
        foreach ($chunks as $chunk) {
            $ins->execute([$botId, $sourceId, $chunk, now()]);
        }

        $upd = $pdo->prepare('UPDATE sources SET status = ?, chunk_count = ?, error_message = ? WHERE id = ?');
        $upd->execute([count($chunks) > 0 ? 'indexed' : 'error', count($chunks), count($chunks) > 0 ? '' : 'No usable text found.', $sourceId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $upd = $pdo->prepare('UPDATE sources SET status = ?, error_message = ? WHERE id = ?');
        $upd->execute(['error', $e->getMessage(), $sourceId]);
    }
}

/** Build a safe FTS5 MATCH expression from free-form visitor text. */
function fts_query_from_text(string $text): ?string
{
    preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $m);
    $terms = array_slice(array_unique($m[0]), 0, 12);
    if (empty($terms)) {
        return null;
    }
    // Quote each term so punctuation/keywords in the source text can't break
    // FTS5 query syntax, then OR them together (any-term match, best ranked first).
    $quoted = array_map(fn($t) => '"' . str_replace('"', '""', $t) . '"', $terms);
    return implode(' OR ', $quoted);
}

/** @return string[] top-K matching chunk contents for this bot. */
function search_chunks(int $botId, string $question, int $k = 5): array
{
    $match = fts_query_from_text($question);
    if ($match === null) {
        return [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT c.content
             FROM chunks_fts
             JOIN chunks c ON c.id = chunks_fts.rowid
             WHERE chunks_fts MATCH :match AND c.bot_id = :bot_id
             ORDER BY chunks_fts.rank
             LIMIT :k'
        );
        $stmt->bindValue(':match', $match, PDO::PARAM_STR);
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':k', $k, PDO::PARAM_INT);
        $stmt->execute();
        return array_column($stmt->fetchAll(), 'content');
    } catch (Throwable $e) {
        // A pathological query should degrade to "no context found", never a 500.
        return [];
    }
}

function bot_has_trained_content(int $botId): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) AS n FROM chunks WHERE bot_id = ?');
    $stmt->execute([$botId]);
    return (int)$stmt->fetch()['n'] > 0;
}
