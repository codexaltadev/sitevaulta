<?php

namespace App\Enums;

enum FileProtocol: string
{
    case None = 'none';
    case SshSftp = 'ssh_sftp';
    case FtpFtps = 'ftp_ftps';
    case CPanelApi = 'cpanel_api';
    case VaultixAgent = 'vaultix_agent';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None (Database Only)',
            self::SshSftp => 'SSH / SFTP (VPS & Dedicated Servers)',
            self::FtpFtps => 'FTP / FTPS (Shared Hosting)',
            self::CPanelApi => 'cPanel API (Shared / Managed Hosting)',
            self::VaultixAgent => 'Vaultix / SiteVaulta Agent',
        };
    }

    public function supportsRemoteStreamCompression(): bool
    {
        return in_array($this, [self::SshSftp, self::CPanelApi, self::VaultixAgent], true);
    }

    public function supportsIncrementalDiff(): bool
    {
        return $this !== self::None;
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

