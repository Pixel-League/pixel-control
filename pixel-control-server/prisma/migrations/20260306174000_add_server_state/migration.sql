-- CreateTable
CREATE TABLE "server_states" (
    "id" TEXT NOT NULL,
    "server_id" TEXT NOT NULL,
    "state" JSONB NOT NULL,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "server_states_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "server_states_server_id_key" ON "server_states"("server_id");

-- AddForeignKey
ALTER TABLE "server_states" ADD CONSTRAINT "server_states_server_id_fkey" FOREIGN KEY ("server_id") REFERENCES "servers"("id") ON DELETE CASCADE ON UPDATE CASCADE;
