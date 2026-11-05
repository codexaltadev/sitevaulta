<?php

namespace App\Http\Controllers;

use App\Models\BackupArtifact;
use App\Services\Backup\ArchivePackagerService;
use App\Services\Restore\DownloadTokenService;
use App\Services\Storage\StorageAdapterManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DownloadController extends Controller
{
    public function downloadOneTime(string $token): Response|StreamedResponse
    {
        try {
            $tokenRecord = DownloadTokenService::claimOneTimeToken($token);
        } catch (GoneHttpException $e) {
            return response()->view('errors.download-token-error', [
                'statusCode' => 410,
                'heading' => 'Download Link Already Used or Expired',
                'message' => $e->getMessage(),
                'subtext' => 'For your security, backup download links are single-use tokens that expire after use. To download this file again, return to your dashboard and generate a fresh link with one click.',
            ], 410);
        } catch (NotFoundHttpException $e) {
            return response()->view('errors.download-token-error', [
                'statusCode' => 404,
                'heading' => 'Invalid Download Link',
                'message' => 'This download token was not found or has been revoked.',
                'subtext' => 'Please return to your SiteVaulta dashboard to generate a valid download link.',
            ], 404);
        }

        $artifact = $tokenRecord->backupArtifact;

        if (! $artifact) {
            return response()->view('errors.download-token-error', [
                'statusCode' => 404,
                'heading' => 'Backup File Not Found',
                'message' => 'The backup archive associated with this token could not be found.',
                'subtext' => 'The backup record may have been pruned or deleted.',
            ], 404);
        }

        $cachedAuth = Cache::pull("sitevaulta:download_decrypt:{$tokenRecord->token_hash}");
        $masterPass = is_array($cachedAuth) ? ($cachedAuth['master_passphrase'] ?? null) : $cachedAuth;
        $customPass = is_array($cachedAuth) ? ($cachedAuth['custom_passphrase'] ?? null) : null;

        // If decryption on download or custom re-encryption was requested
        if ($masterPass && $artifact->is_encrypted) {
            $localMirrorPath = Storage::disk('local')->path('vault-mirror/' . ltrim($artifact->remote_path, '/'));
            $tempSourceFile = null;

            if (! file_exists($localMirrorPath)) {
                $stream = StorageAdapterManager::openArtifactReadStream($artifact);
                if (is_resource($stream)) {
                    $tempSourceFile = tempnam(sys_get_temp_dir(), 'sv_src_');
                    $tempHandle = fopen($tempSourceFile, 'wb');
                    while (! feof($stream)) {
                        fwrite($tempHandle, fread($stream, 65536));
                    }
                    fclose($tempHandle);
                    fclose($stream);
                    $localMirrorPath = $tempSourceFile;
                }
            }

            if (file_exists($localMirrorPath)) {
                $tempOutputFile = tempnam(sys_get_temp_dir(), 'sv_out_') . '.zip';
                $operationOk = false;

                if (! empty($customPass)) {
                    // Re-encrypt with custom client password for WinRAR / 7-Zip / WinZip
                    $operationOk = ArchivePackagerService::reEncryptToCustomPasswordZip(
                        $localMirrorPath,
                        $tempOutputFile,
                        $masterPass,
                        $customPass
                    );
                    $downloadFileName = preg_replace('/\.zip(\.enc)?$/i', '_custom_protected.zip', $artifact->file_name);
                } else {
                    // Decrypt to plain unencrypted zip
                    $operationOk = ArchivePackagerService::decryptToPlainZip(
                        $localMirrorPath,
                        $tempOutputFile,
                        $masterPass
                    );
                    $downloadFileName = preg_replace('/\.zip(\.enc)?$/i', '_decrypted.zip', $artifact->file_name);
                }

                if ($tempSourceFile && file_exists($tempSourceFile)) {
                    @unlink($tempSourceFile);
                }

                if ($operationOk && file_exists($tempOutputFile)) {
                    $outputSize = filesize($tempOutputFile);
                    $stream = fopen($tempOutputFile, 'rb');

                    return response()->streamDownload(function () use ($stream, $tokenRecord, $tempOutputFile): void {
                        while (! feof($stream)) {
                            echo fread($stream, 65536);
                            flush();
                        }
                        fclose($stream);
                        @unlink($tempOutputFile);

                        DownloadTokenService::markConsumed($tokenRecord);
                    }, $downloadFileName, [
                        'Content-Type' => 'application/zip',
                        'Content-Length' => (string) $outputSize,
                    ]);
                }
            }
        }

        $stream = StorageAdapterManager::openArtifactReadStream($artifact);
        if (! is_resource($stream)) {
            throw new NotFoundHttpException('Backup file stream unavailable on storage.');
        }

        return response()->streamDownload(function () use ($stream, $tokenRecord): void {
            while (! feof($stream)) {
                echo fread($stream, 65536);
                flush();
            }
            fclose($stream);

            // Atomically mark token as consumed after full stream completes
            DownloadTokenService::markConsumed($tokenRecord);
        }, $artifact->file_name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $artifact->size_bytes,
            'X-SiteVaulta-Checksum-SHA256' => $artifact->sha256_checksum,
        ]);
    }

    public function downloadSigned(Request $request, string $artifactUuid): Response|StreamedResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->view('errors.download-token-error', [
                'statusCode' => 403,
                'heading' => 'Signed Download Link Expired',
                'message' => 'This signed download link has expired or has an invalid signature.',
                'subtext' => 'For security reasons, signed download links are time-limited (15 minutes). Please return to your dashboard to generate a fresh link.',
            ], 403);
        }

        $artifact = BackupArtifact::where('uuid', $artifactUuid)->first();
        if (! $artifact) {
            return response()->view('errors.download-token-error', [
                'statusCode' => 404,
                'heading' => 'Backup File Not Found',
                'message' => 'The requested backup artifact could not be found.',
                'subtext' => 'Please return to your dashboard to check available backups.',
            ], 404);
        }

        $stream = StorageAdapterManager::openArtifactReadStream($artifact);

        if (! is_resource($stream)) {
            return response()->view('errors.download-token-error', [
                'statusCode' => 404,
                'heading' => 'Backup Stream Unavailable',
                'message' => 'The backup file stream is temporarily unavailable on storage.',
                'subtext' => 'Please try again in a few moments or verify your storage connection status.',
            ], 404);
        }

        return response()->streamDownload(function () use ($stream): void {
            while (! feof($stream)) {
                echo fread($stream, 65536);
                flush();
            }
            fclose($stream);
        }, $artifact->file_name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $artifact->size_bytes,
            'X-SiteVaulta-Checksum-SHA256' => $artifact->sha256_checksum,
        ]);
    }
}

