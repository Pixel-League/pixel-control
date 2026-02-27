-- CreateTable
CREATE TABLE "servers" (
    "id" TEXT NOT NULL,
    "server_login" TEXT NOT NULL,
    "server_name" TEXT,
    "link_token" TEXT,
    "linked" BOOLEAN NOT NULL DEFAULT false,
    "game_mode" TEXT,
    "title_id" TEXT,
    "plugin_version" TEXT,
    "last_heartbeat" TIMESTAMP(3),
    "online" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "servers_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "connectivity_events" (
    "id" TEXT NOT NULL,
    "server_id" TEXT NOT NULL,
    "event_name" TEXT NOT NULL,
    "event_id" TEXT NOT NULL,
    "event_category" TEXT NOT NULL,
    "idempotency_key" TEXT NOT NULL,
    "source_callback" TEXT NOT NULL,
    "source_sequence" INTEGER NOT NULL,
    "source_time" INTEGER NOT NULL,
    "schema_version" TEXT NOT NULL,
    "payload" JSONB NOT NULL,
    "metadata" JSONB,
    "received_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "connectivity_events_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "servers_server_login_key" ON "servers"("server_login");

-- CreateIndex
CREATE UNIQUE INDEX "connectivity_events_idempotency_key_key" ON "connectivity_events"("idempotency_key");

-- CreateIndex
CREATE INDEX "connectivity_events_server_id_idx" ON "connectivity_events"("server_id");

-- CreateIndex
CREATE INDEX "connectivity_events_event_category_idx" ON "connectivity_events"("event_category");

-- AddForeignKey
ALTER TABLE "connectivity_events" ADD CONSTRAINT "connectivity_events_server_id_fkey" FOREIGN KEY ("server_id") REFERENCES "servers"("id") ON DELETE RESTRICT ON UPDATE CASCADE;
