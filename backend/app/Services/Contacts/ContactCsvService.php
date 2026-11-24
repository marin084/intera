<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\Channel;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class ContactCsvService
{
    public const MAX_ROWS = 10000;

    /** @return array{headers:list<string>,sample:list<array<string,string>>,row_count:int,suggested_mapping:array<string,string|null>} */
    public function preview(UploadedFile $file): array
    {
        $csv = $this->readCsv($file);
        $suggestedMapping = $this->suggestMapping($csv['headers']);

        return [
            'headers' => $csv['headers'],
            'sample' => array_slice($csv['rows'], 0, 5),
            'row_count' => count($csv['rows']),
            'suggested_mapping' => $suggestedMapping,
        ];
    }

    /**
     * Contacts are upserted by source id, then email, then phone. The import
     * never creates conversations or sends outbound messages.
     *
     * @param array<string,string|null> $mapping
     * @return array{total_rows:int,created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>}
     */
    public function import(UploadedFile $file, array $mapping, ?string $destinationChannelId = null): array
    {
        $csv = $this->readCsv($file);
        $headers = $csv['headers'];
        $allowedRoles = [
            'external_identifier', 'display_name', 'first_name', 'last_name', 'language',
            'country', 'phone', 'email', 'platforms', 'whatsapp_bsuids', 'custom_fields',
        ];
        if (array_diff(array_keys($mapping), $allowedRoles) !== []) {
            throw new RuntimeException('El mapeo contiene campos de INTERA no reconocidos.');
        }

        $invalidColumns = array_values(array_filter($mapping, fn ($column): bool => $column !== null && ! in_array($column, $headers, true)));
        if ($invalidColumns !== []) {
            throw new RuntimeException('El mapeo contiene columnas que no existen en el archivo.');
        }

        $mappedColumns = array_values(array_filter($mapping, fn ($column): bool => is_string($column) && $column !== ''));
        $hasIdentity = (bool) (($mapping['external_identifier'] ?? null) || ($mapping['email'] ?? null) || ($mapping['phone'] ?? null));
        if (! $hasIdentity) {
            throw new RuntimeException('Mapea al menos un identificador, teléfono o correo para poder importar.');
        }

        if (! is_string($destinationChannelId) || $destinationChannelId === '') {
            throw new RuntimeException('Selecciona un canal destino antes de importar.');
        }

        $availableChannel = Channel::query()
            ->where('is_active', true)
            ->whereKey($destinationChannelId)
            ->first();
        if (! $availableChannel) {
            throw new RuntimeException('El canal destino no pertenece al Workspace activo o está inactivo.');
        }

        $summary = ['total_rows' => count($csv['rows']), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($csv['rows'] as $index => $rawRow) {
            $rowNumber = $index + 2;
            try {
                $row = $this->mappedRow($rawRow, $mapping, $mappedColumns);
                $result = $this->upsertContact($row, $destinationChannelId);
                $summary[$result]++;
            } catch (RuntimeException $exception) {
                $summary['errors'][] = ['row' => $rowNumber, 'message' => $exception->getMessage()];
                $summary['skipped']++;
            }
        }

        if ($summary['total_rows'] === 0) {
            throw new RuntimeException('El archivo CSV no contiene filas de contactos.');
        }

        return $summary;
    }

    /** @param iterable<Contact> $contacts */
    public function writeExport(iterable $contacts, mixed $handle): void
    {
        // UTF-8 BOM improves compatibility with Excel while preserving accents.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['id', 'firstName', 'lastName', 'language', 'country', 'platforms', 'channels', 'phoneNumbers', 'emails', 'whatsappBsuids', 'customFields']);

        foreach ($contacts as $contact) {
            $metadata = is_array($contact->metadata) ? $contact->metadata : [];
            $import = is_array($metadata['contact_import'] ?? null) ? $metadata['contact_import'] : [];
            $nameParts = $this->nameParts($contact->display_name, $import);

            $row = [
                $contact->external_identifier ?? $contact->id,
                $nameParts['first_name'],
                $nameParts['last_name'],
                $import['language'] ?? '',
                $contact->country ?? '',
                $this->joinList($import['platforms'] ?? []),
                $this->joinList($this->linkedChannelNames($import['linked_channel_ids'] ?? [])),
                $this->joinList($import['phone_numbers'] ?? ($contact->phone ? [$contact->phone] : [])),
                $this->joinList($import['emails'] ?? ($contact->email ? [$contact->email] : [])),
                $this->joinList($import['whatsapp_bsuids'] ?? []),
                ! empty($import['extra_fields']) ? json_encode($this->sanitizeExportValue($import['extra_fields']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            ];
            fputcsv($handle, array_map(fn (mixed $value): string => $this->safeCsvValue($value), $row));
        }
    }

    /** @return array{headers:list<string>,rows:list<array<string,string>>} */
    private function readCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path) {
            throw new RuntimeException('No se pudo leer el archivo CSV.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new RuntimeException('El archivo CSV está vacío.');
            }
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            rewind($handle);
            $rawHeaders = fgetcsv($handle, 0, $delimiter);
            $headers = $this->cleanHeaders($rawHeaders ?: []);
            if ($headers === [] || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('El CSV debe tener encabezados únicos.');
            }

            $rows = [];
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($this->rowIsEmpty($values)) {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new RuntimeException('El archivo supera el máximo de '.self::MAX_ROWS.' filas por importación.');
                }
                $rows[] = $this->rowFromValues($headers, $values);
            }

            return ['headers' => $headers, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $headers @return array<string,string|null> */
    private function suggestMapping(array $headers): array
    {
        $roles = [
            'external_identifier' => ['id', 'external_id', 'external_identifier', 'identifier', 'identificador', 'cliente', 'customer_id', 'contact_id'],
            'display_name' => ['display_name', 'fullname', 'full_name', 'nombre_completo'],
            'first_name' => ['firstname', 'first_name', 'nombre'],
            'last_name' => ['lastname', 'last_name', 'apellido'],
            'language' => ['language', 'idioma', 'lang'],
            'country' => ['country', 'pais', 'país'],
            'phone' => ['phone', 'phone_number', 'phonenumber', 'phonenumbers', 'telefono', 'teléfono', 'mobile'],
            'email' => ['email', 'emails', 'correo', 'correo_electronico'],
            'platforms' => ['platform', 'platforms', 'plataforma', 'plataformas'],
            'whatsapp_bsuids' => ['whatsappbsuids', 'whatsapp_bsuids'],
            'custom_fields' => ['customfields', 'custom_fields', 'campos', 'campos_personalizados', 'metadata'],
        ];
        $suggested = [];
        foreach ($roles as $role => $aliases) {
            $suggested[$role] = null;
            foreach ($headers as $header) {
                if (in_array($this->normalizedHeader($header), $aliases, true)) {
                    $suggested[$role] = $header;
                    break;
                }
            }
        }

        return $suggested;
    }

    /** @param array<string,string> $rawRow @param array<string,string|null> $mapping @param list<string> $mappedColumns @return array<string,mixed> */
    private function mappedRow(array $rawRow, array $mapping, array $mappedColumns): array
    {
        $value = fn (string $role): string => $this->clean((string) (($mapping[$role] ?? null) ? ($rawRow[$mapping[$role]] ?? '') : ''));
        $extras = [];
        foreach ($rawRow as $header => $headerValue) {
            if (! in_array($header, $mappedColumns, true) && $this->clean($headerValue) !== '') {
                $extras[$header] = $this->clean($headerValue);
            }
        }

        $mappedCustomFields = $value('custom_fields');
        if ($mappedCustomFields !== '') {
            $decoded = json_decode($mappedCustomFields, true);
            if (is_array($decoded)) {
                $extras = array_merge($extras, $decoded);
            } else {
                $extras['custom_fields'] = $mappedCustomFields;
            }
        }

        return [
            'id' => $value('external_identifier'),
            'display_name' => $value('display_name'),
            'first_name' => $value('first_name'),
            'last_name' => $value('last_name'),
            'language' => $value('language'),
            'country' => $value('country'),
            'phone_numbers' => $value('phone'),
            'emails' => $value('email'),
            'platforms' => $value('platforms'),
            'whatsapp_bsuids' => $value('whatsapp_bsuids'),
            'extra_fields' => $extras,
        ];
    }

    /** @param list<string> $headers @return list<string> */
    private function cleanHeaders(array $headers): array
    {
        return array_map(function ($header): string {
            $value = trim((string) $header);

            return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        }, $headers);
    }

    private function normalizedHeader(string $header): string
    {
        $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($header)) : trim($header);

        return strtolower((string) preg_replace('/[^a-z0-9]+/', '_', (string) $ascii));
    }

    /** @param list<string>|false $values */
    private function rowIsEmpty(array|false $values): bool
    {
        return $values === false || count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0;
    }

    /** @param list<string> $headers @param list<string> $values @return array<string,string> */
    private function rowFromValues(array $headers, array $values): array
    {
        $row = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return 'created'|'updated' */
    private function upsertContact(array $row, ?string $destinationChannelId = null): string
    {
        $externalIdentifier = $this->clean((string) ($row['id'] ?? ''));
        $email = $this->firstListValue((string) ($row['emails'] ?? ''));
        $phone = $this->firstListValue((string) ($row['phone_numbers'] ?? ''));

        if ($externalIdentifier === '' && $email === '' && $phone === '') {
            throw new RuntimeException('La fila no tiene id, teléfono ni correo.');
        }

        $contact = null;
        if ($externalIdentifier !== '') {
            $contact = Contact::query()->where('external_identifier', $externalIdentifier)->first();
        }
        if (! $contact && $email !== '') {
            $contact = Contact::query()->where('email', $email)->first();
        }
        if (! $contact && $phone !== '') {
            $contact = Contact::query()->where('phone', $phone)->first();
        }

        $metadata = is_array($contact?->metadata) ? $contact->metadata : [];
        $firstName = $this->clean((string) ($row['first_name'] ?? ''));
        $lastName = $this->clean((string) ($row['last_name'] ?? ''));
        $displayName = $this->clean((string) ($row['display_name'] ?? '')) ?: trim($firstName.' '.$lastName);
        $linkedChannelIds = $destinationChannelId ? [$destinationChannelId] : [];
        $metadata['directory_source'] = 'import';
        $metadata['contact_import'] = [
            'source' => 'csv',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'language' => $this->clean((string) ($row['language'] ?? '')),
            'platforms' => $this->splitList((string) ($row['platforms'] ?? '')),
            'linked_channel_ids' => array_values(array_unique($linkedChannelIds)),
            'phone_numbers' => $this->splitList((string) ($row['phone_numbers'] ?? '')),
            'emails' => $this->splitList((string) ($row['emails'] ?? '')),
            'whatsapp_bsuids' => $this->splitList((string) ($row['whatsapp_bsuids'] ?? '')),
            'extra_fields' => is_array($row['extra_fields'] ?? null) ? $row['extra_fields'] : [],
        ];

        $attributes = [
            'external_identifier' => $externalIdentifier !== '' ? $externalIdentifier : ($contact?->external_identifier),
            'display_name' => $displayName !== '' ? $displayName : ($contact?->display_name),
            'country' => $this->clean((string) ($row['country'] ?? '')) ?: ($contact?->country),
            'phone' => $phone !== '' ? $phone : ($contact?->phone),
            'email' => $email !== '' ? $email : ($contact?->email),
            'metadata' => $metadata,
        ];

        if ($contact) {
            $contact->update($attributes);

            return 'updated';
        }

        Contact::create($attributes);

        return 'created';
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @return list<string> */
    private function splitList(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $parts = preg_split('/\s*[|;]\s*|\s*,\s*/', $value) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($part): string => $this->clean((string) $part), $parts))));
    }

    private function firstListValue(string $value): string
    {
        return $this->splitList($value)[0] ?? '';
    }

    /** @return array{first_name:string,last_name:string} */
    private function nameParts(?string $displayName, array $import): array
    {
        $firstName = trim((string) ($import['first_name'] ?? ''));
        $lastName = trim((string) ($import['last_name'] ?? ''));
        if ($firstName !== '' || $lastName !== '') {
            return ['first_name' => $firstName, 'last_name' => $lastName];
        }

        return ['first_name' => trim((string) $displayName), 'last_name' => ''];
    }

    private function joinList(mixed $value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return implode(';', array_map(fn ($item): string => (string) $item, $value));
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    private function sanitizeExportValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $nestedValue) {
            $key = (string) $key;
            if ($this->isSensitiveKey($key)) {
                continue;
            }
            $sanitized[$key] = $this->sanitizeExportValue($nestedValue);
        }

        return $sanitized;
    }

    /** @param mixed $channelIds @return list<string> */
    private function linkedChannelNames(mixed $channelIds): array
    {
        if (! is_array($channelIds) || $channelIds === []) {
            return [];
        }

        $ids = array_values(array_filter($channelIds, fn ($id): bool => is_string($id) && $id !== ''));
        if ($ids === []) {
            return [];
        }

        return Channel::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->values()
            ->all();
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match('/token|secret|password|access[_-]?key|api[_-]?key|authorization|credential/i', $key) === 1;
    }
}
