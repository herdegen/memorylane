-- PostgreSQL initialization script for MemoryLane

-- Enable required extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- Set timezone
SET timezone = 'Europe/Paris';

-- Create custom types
DO $$ BEGIN
    CREATE TYPE media_type AS ENUM ('photo', 'video', 'document');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

-- Grant permissions
GRANT ALL PRIVILEGES ON DATABASE memorylane TO memorylane;
