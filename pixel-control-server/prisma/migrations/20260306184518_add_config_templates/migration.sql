-- AlterTable
ALTER TABLE "servers" ADD COLUMN     "config_template_id" TEXT;

-- CreateTable
CREATE TABLE "config_templates" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "config" JSONB NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "config_templates_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "config_templates_name_key" ON "config_templates"("name");

-- AddForeignKey
ALTER TABLE "servers" ADD CONSTRAINT "servers_config_template_id_fkey" FOREIGN KEY ("config_template_id") REFERENCES "config_templates"("id") ON DELETE SET NULL ON UPDATE CASCADE;
