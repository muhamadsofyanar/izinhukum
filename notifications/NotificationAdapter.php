<?php

interface NotificationAdapter {
    /**
     * Kirim notifikasi via provider ini.
     * @return array ['success' => bool, 'message' => string, 'raw_response' => mixed]
     */
    public function send(string $recipient, string $subject, string $body): array;

    public function getName(): string;
}
