-- CreateTable
CREATE TABLE "events" (
    "id" TEXT NOT NULL,
    "server_id" TEXT NOT NULL,
    "event_name" TEXT NOT NULL,
    "event_id" TEXT NOT NULL,
    "event_category" TEXT NOT NULL,
    "idempotency_key" TEXT NOT NULL,
    "source_callback" TEXT NOT NULL,
    "source_sequence" BIGINT NOT NULL,
    "source_time" BIGINT NOT NULL,
    "schema_version" TEXT NOT NULL,
    "payload" JSONB NOT NULL,
    "metadata" JSONB,
    "received_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "events_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "events_idempotency_key_key" ON "events"("idempotency_key");

-- CreateIndex
CREATE INDEX "events_server_id_idx" ON "events"("server_id");

-- CreateIndex
CREATE INDEX "events_event_category_idx" ON "events"("event_category");

-- CreateIndex
CREATE INDEX "events_server_id_event_category_idx" ON "events"("server_id", "event_category");

-- CreateIndex
CREATE INDEX "events_source_time_idx" ON "events"("source_time");

-- CreateIndex
CREATE INDEX "events_server_id_event_category_source_time_idx" ON "events"("server_id", "event_category", "source_time");

-- AddForeignKey
ALTER TABLE "events" ADD CONSTRAINT "events_server_id_fkey" FOREIGN KEY ("server_id") REFERENCES "servers"("id") ON DELETE CASCADE ON UPDATE CASCADE;
