<?php

namespace App\Console\Commands;

use App\Models\CustomerDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * ONE-OFF ops command (safe to delete after use).
 *
 * Copies customer_documents that still exist on the local 'public' disk
 * (written before the switch to the 's3' disk / Supabase Storage) into the
 * new bucket, rewriting file_path to the unified "customer_documents/{id}/..."
 * convention along the way. Run this from the SAME container instance where
 * the files were originally uploaded — the 'public' disk is the ephemeral
 * App Platform filesystem, so once that instance is redeployed/restarted the
 * source files are gone and this command has nothing left to migrate.
 *
 * - Idempotent: a row already pointing at an s3-existing path is skipped.
 * - --dry-run: reports what would happen without writing to s3 or the DB.
 */
class MigrateDocumentsToS3 extends Command
{
    protected $signature = 'documents:migrate-to-s3 {--dry-run}';
    protected $description = 'One-off: copy surviving customer_documents from the local public disk to the s3 disk.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $migrated = 0;
        $skippedNoCustomer = 0;
        $skippedMissingLocal = 0;
        $skippedAlreadyOnS3 = 0;

        CustomerDocument::orderBy('id')->chunk(50, function ($documents) use (
            $dryRun, &$migrated, &$skippedNoCustomer, &$skippedMissingLocal, &$skippedAlreadyOnS3
        ) {
            foreach ($documents as $document) {
                $legacyPath = $document->file_path;

                if (Storage::disk('s3')->exists($legacyPath)) {
                    $skippedAlreadyOnS3++;
                    $this->line("SKIP (already on s3): #{$document->id} {$legacyPath}");
                    continue;
                }

                if (!Storage::disk('public')->exists($legacyPath)) {
                    $skippedMissingLocal++;
                    $this->warn("SKIP (missing on local disk, likely lost): #{$document->id} {$legacyPath}");
                    continue;
                }

                if (!$document->customer_id) {
                    $skippedNoCustomer++;
                    $this->warn("SKIP (no customer_id yet, prospect-only): #{$document->id} {$legacyPath}");
                    continue;
                }

                $newPath = "customer_documents/{$document->customer_id}/" . basename($legacyPath);

                $this->info(($dryRun ? '[DRY-RUN] ' : '') . "MIGRATE: #{$document->id} {$legacyPath} -> {$newPath}");

                if ($dryRun) {
                    $migrated++;
                    continue;
                }

                $bytes = Storage::disk('public')->get($legacyPath);
                Storage::disk('s3')->put($newPath, $bytes);
                $document->update(['file_path' => $newPath]);
                $migrated++;
            }
        });

        $this->newLine();
        $this->info("Migrated: {$migrated}");
        $this->info("Skipped (already on s3): {$skippedAlreadyOnS3}");
        $this->info("Skipped (missing on local disk): {$skippedMissingLocal}");
        $this->info("Skipped (no customer_id / prospect-only): {$skippedNoCustomer}");

        return self::SUCCESS;
    }
}
