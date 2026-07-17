-- init.sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE IF NOT EXISTS documents (
    id SERIAL PRIMARY KEY,
    filename TEXT NOT NULL,
    image_data BYTEA,
    thumb_data BYTEA,
    mime_type TEXT NOT NULL,
    file_size BIGINT NOT NULL,
    width INTEGER,
    height INTEGER,
    metadata JSONB DEFAULT '{}'::jsonb,
    description TEXT,
    uploaded_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    search_vector TSVECTOR GENERATED ALWAYS AS (
        setweight(to_tsvector('russian', coalesce(filename,'')), 'A') ||
        setweight(to_tsvector('russian', coalesce(description,'')), 'B')
    ) STORED
);

CREATE INDEX idx_documents_uploaded_at ON documents (uploaded_at);
CREATE INDEX idx_documents_metadata_gin ON documents USING GIN (metadata);
CREATE INDEX idx_documents_search_vector ON documents USING GIN (search_vector);
CREATE INDEX idx_documents_filename_trgm ON documents USING GIN (filename gin_trgm_ops);

CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_documents_updated_at
BEFORE UPDATE ON documents
FOR EACH ROW EXECUTE FUNCTION update_updated_at();