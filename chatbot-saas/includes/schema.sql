-- ChatBot Builder - SQLite schema
-- Applied once by install.php via PDO::exec(). Safe to re-run (CREATE TABLE IF NOT EXISTS).

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email           TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    name            TEXT NOT NULL DEFAULT '',
    plan            TEXT NOT NULL DEFAULT 'trial',   -- trial | front_end | oto1_unlimited | oto3_agency | oto4_whitelabel
    own_api_key     TEXT NOT NULL DEFAULT '',         -- BYOK: customer's own Anthropic key (optional)
    credits_used_month INTEGER NOT NULL DEFAULT 0,
    credits_reset_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-01', 'now')),
    is_agency       INTEGER NOT NULL DEFAULT 0,       -- OTO3: can create client (sub) accounts
    parent_user_id  INTEGER,                          -- set when this user is a client of an agency
    white_label     INTEGER NOT NULL DEFAULT 0,       -- OTO4
    status          TEXT NOT NULL DEFAULT 'active',    -- active | suspended
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS licenses (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    license_key     TEXT NOT NULL UNIQUE,
    plan            TEXT NOT NULL,                    -- matches users.plan values
    email           TEXT NOT NULL DEFAULT '',          -- buyer email from WarriorPlus, pre-fills registration
    wplus_txn_id    TEXT NOT NULL DEFAULT '',
    wplus_product_id TEXT NOT NULL DEFAULT '',
    redeemed_by_user_id INTEGER,
    redeemed_at     TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (redeemed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS bots (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    name            TEXT NOT NULL,
    widget_key      TEXT NOT NULL UNIQUE,              -- public identifier embedded in customer sites
    welcome_message TEXT NOT NULL DEFAULT 'Hi! How can I help you today?',
    persona         TEXT NOT NULL DEFAULT 'You are a friendly, concise assistant for this business. Answer only from the provided context. If you do not know, offer to collect the visitor''s contact details.',
    model           TEXT NOT NULL DEFAULT '',           -- '' = use DEFAULT_MODEL
    color           TEXT NOT NULL DEFAULT '#4f46e5',
    avatar_url      TEXT NOT NULL DEFAULT '',
    lead_capture    INTEGER NOT NULL DEFAULT 1,
    allowed_domain  TEXT NOT NULL DEFAULT '',           -- '' = any domain (dev); else restrict widget to this host
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sources (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    bot_id          INTEGER NOT NULL,
    type            TEXT NOT NULL,                     -- text | url | file
    title           TEXT NOT NULL DEFAULT '',
    origin          TEXT NOT NULL DEFAULT '',           -- the URL, filename, or '(pasted text)'
    status          TEXT NOT NULL DEFAULT 'pending',    -- pending | indexed | error
    error_message   TEXT NOT NULL DEFAULT '',
    chunk_count     INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS chunks (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    bot_id          INTEGER NOT NULL,
    source_id       INTEGER NOT NULL,
    content         TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
);

-- Full-text index over chunk content. content_rowid links back to chunks.id
-- so a MATCH query returns rowids we can join straight back to chunks.
CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5(
    content,
    content = 'chunks',
    content_rowid = 'id',
    tokenize = 'porter unicode61'
);

-- Keep the FTS index in sync with chunks automatically.
CREATE TRIGGER IF NOT EXISTS chunks_ai AFTER INSERT ON chunks BEGIN
    INSERT INTO chunks_fts(rowid, content) VALUES (new.id, new.content);
END;
CREATE TRIGGER IF NOT EXISTS chunks_ad AFTER DELETE ON chunks BEGIN
    INSERT INTO chunks_fts(chunks_fts, rowid, content) VALUES ('delete', old.id, old.content);
END;
CREATE TRIGGER IF NOT EXISTS chunks_au AFTER UPDATE ON chunks BEGIN
    INSERT INTO chunks_fts(chunks_fts, rowid, content) VALUES ('delete', old.id, old.content);
    INSERT INTO chunks_fts(rowid, content) VALUES (new.id, new.content);
END;

CREATE TABLE IF NOT EXISTS conversations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    bot_id          INTEGER NOT NULL,
    visitor_id      TEXT NOT NULL DEFAULT '',
    visitor_meta    TEXT NOT NULL DEFAULT '',           -- JSON: page url, referrer, user agent
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    last_message_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    role            TEXT NOT NULL,                      -- user | assistant
    content         TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leads (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    bot_id          INTEGER NOT NULL,
    conversation_id INTEGER,
    name            TEXT NOT NULL DEFAULT '',
    email           TEXT NOT NULL DEFAULT '',
    phone           TEXT NOT NULL DEFAULT '',
    message         TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_bots_user ON bots(user_id);
CREATE INDEX IF NOT EXISTS idx_sources_bot ON sources(bot_id);
CREATE INDEX IF NOT EXISTS idx_chunks_bot ON chunks(bot_id);
CREATE INDEX IF NOT EXISTS idx_chunks_source ON chunks(source_id);
CREATE INDEX IF NOT EXISTS idx_conversations_bot ON conversations(bot_id);
CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id);
CREATE INDEX IF NOT EXISTS idx_leads_bot ON leads(bot_id);
