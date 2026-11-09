CREATE EXTENSION IF NOT EXISTS vector;

SELECT 'CREATE DATABASE ledgerly_test OWNER ledgerly'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'ledgerly_test')\gexec

\connect ledgerly_test

CREATE EXTENSION IF NOT EXISTS vector;
