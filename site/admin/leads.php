<?php
declare(strict_types=1);

if (!defined('ALYM_STORAGE_DIR')) {
    throw new RuntimeException('Storage directory is not configured.');
}

function leads_path(): string {
    return ALYM_STORAGE_DIR . '/leads.json';
}

function load_leads(): array {
    $path = leads_path();
    if (!is_file($path)) return array();
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? array_values($data) : array();
}

function mutate_leads(callable $mutator): array {
    $path = leads_path();
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0770, true);
    $handle = fopen($path, 'c+');
    if (!$handle) throw new RuntimeException('Не удалось открыть хранилище заявок.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Не удалось заблокировать хранилище заявок.');
        rewind($handle);
        $data = json_decode(stream_get_contents($handle) ?: '[]', true);
        $leads = is_array($data) ? array_values($data) : array();
        $leads = $mutator($leads);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode(array_values($leads), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($handle);
        flock($handle, LOCK_UN);
        return $leads;
    } finally {
        fclose($handle);
    }
}

function add_lead(array $lead): void {
    mutate_leads(function (array $leads) use ($lead): array {
        array_unshift($leads, $lead);
        return array_slice($leads, 0, 5000);
    });
}

function find_lead(string $id): ?array {
    foreach (load_leads() as $lead) {
        if (($lead['id'] ?? '') === $id) return $lead;
    }
    return null;
}

function set_lead_status(string $id, string $status): void {
    mutate_leads(function (array $leads) use ($id, $status): array {
        foreach ($leads as &$lead) {
            if (($lead['id'] ?? '') === $id) $lead['status'] = $status;
        }
        unset($lead);
        return $leads;
    });
}

function delete_lead(string $id): void {
    $lead = find_lead($id);
    mutate_leads(fn(array $leads): array => array_values(array_filter($leads, fn(array $item): bool => ($item['id'] ?? '') !== $id)));
    foreach ($lead['files'] ?? array() as $file) {
        $relative = str_replace(array('..', '\\'), '', (string)($file['path'] ?? ''));
        $absolute = ALYM_STORAGE_DIR . '/' . ltrim($relative, '/');
        if (is_file($absolute)) unlink($absolute);
    }
    $directory = ALYM_STORAGE_DIR . '/lead-files/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if (is_dir($directory)) @rmdir($directory);
}

function new_leads_count(): int {
    return count(array_filter(load_leads(), fn(array $lead): bool => ($lead['status'] ?? 'new') === 'new'));
}

function lead_field_label(string $key): string {
    return array(
        'form_name' => 'Имя',
        'form_phone' => 'Телефон',
        'form_email' => 'Email',
        'form_message' => 'Сообщение',
        'form_catalog' => 'Товар',
        'form_link_user' => 'Ссылка',
        'name_form' => 'Форма',
        'id_form' => 'Тип формы',
        'quantity' => 'Количество',
    )[$key] ?? $key;
}
