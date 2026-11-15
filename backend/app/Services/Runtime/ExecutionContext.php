<?php

namespace App\Services\Runtime;

use App\Models\Contact;
use App\Services\Presence\AgentAvailabilityService;

class ExecutionContext
{
    public const NAME_ALIASES = ['visitor_name', 'nombre', 'name', 'var_nombre'];
    public const EMAIL_ALIASES = ['email', 'correo'];
    public const PHONE_ALIASES = ['phone', 'telefono', 'tel'];

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        protected array $variables = [],
        protected ?Contact $contact = null
    ) {
        $this->normalizeAliases();
    }

    public static function fromArray(?array $data, ?Contact $contact = null): self
    {
        return new self($data ?? [], $contact);
    }

    protected function normalizeAliases(): void
    {
        foreach (self::NAME_ALIASES as $alias) {
            $val = $this->variables[$alias] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $trimmed = trim($val);
                foreach (self::NAME_ALIASES as $target) {
                    $this->variables[$target] = $trimmed;
                }
                break;
            }
        }

        foreach (self::EMAIL_ALIASES as $alias) {
            $val = $this->variables[$alias] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $trimmed = trim($val);
                foreach (self::EMAIL_ALIASES as $target) {
                    $this->variables[$target] = $trimmed;
                }
                break;
            }
        }

        foreach (self::PHONE_ALIASES as $alias) {
            $val = $this->variables[$alias] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $trimmed = trim($val);
                foreach (self::PHONE_ALIASES as $target) {
                    $this->variables[$target] = $trimmed;
                }
                break;
            }
        }
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;

        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cleanKey = ltrim(trim($key), '$');

        if ($cleanKey === 'system.agents_online' || $cleanKey === 'agents_online') {
            return AgentAvailabilityService::hasOnlineAgents() ? 'TRUE' : 'FALSE';
        }

        if ($cleanKey === 'system.agents_count' || $cleanKey === 'agents_count') {
            return AgentAvailabilityService::getOnlineAgentsCount();
        }

        if ($cleanKey === 'system') {
            return [
                'agents_online' => AgentAvailabilityService::hasOnlineAgents() ? 'TRUE' : 'FALSE',
                'agents_count' => AgentAvailabilityService::getOnlineAgentsCount(),
            ];
        }

        if (str_starts_with($cleanKey, 'contact.')) {
            $prop = substr($cleanKey, 8);
            if ($prop === 'name') {
                if ($this->contact && ! empty($this->contact->display_name)) {
                    $isGeneric = str_starts_with($this->contact->display_name, 'Visitante ') || $this->contact->display_name === 'Visitante';
                    if (! $isGeneric) {
                        return $this->contact->display_name;
                    }
                }
                foreach (self::NAME_ALIASES as $alias) {
                    $val = data_get($this->variables, $alias);
                    if ($val !== null && $val !== '') {
                        return $val;
                    }
                }

                return $default;
            }

            if ($this->contact) {
                if ($prop === 'email') {
                    return $this->contact->email;
                }
                if ($prop === 'phone') {
                    return $this->contact->phone;
                }
                if ($prop === 'identifier') {
                    return $this->contact->external_identifier;
                }
            }
        }

        $direct = data_get($this->variables, $cleanKey) ?? data_get($this->variables, $key);
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        // Check name aliases
        if (in_array($cleanKey, self::NAME_ALIASES, true)) {
            foreach (self::NAME_ALIASES as $alias) {
                $val = data_get($this->variables, $alias);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            }
            if ($this->contact && ! empty($this->contact->display_name)) {
                $isGeneric = str_starts_with($this->contact->display_name, 'Visitante ') || $this->contact->display_name === 'Visitante';
                if (! $isGeneric) {
                    return $this->contact->display_name;
                }
            }
        }

        // Check email aliases
        if (in_array($cleanKey, self::EMAIL_ALIASES, true)) {
            foreach (self::EMAIL_ALIASES as $alias) {
                $val = data_get($this->variables, $alias);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            }
            if ($this->contact && ! empty($this->contact->email)) {
                return $this->contact->email;
            }
        }

        // Check phone aliases
        if (in_array($cleanKey, self::PHONE_ALIASES, true)) {
            foreach (self::PHONE_ALIASES as $alias) {
                $val = data_get($this->variables, $alias);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            }
            if ($this->contact && ! empty($this->contact->phone)) {
                return $this->contact->phone;
            }
        }

        return $default;
    }

    public function set(string $key, mixed $value): self
    {
        $cleanKey = ltrim(trim($key), '$');
        data_set($this->variables, $cleanKey, $value);

        if (in_array($cleanKey, self::NAME_ALIASES, true) && is_string($value) && trim($value) !== '') {
            $trimmed = trim($value);
            foreach (self::NAME_ALIASES as $alias) {
                $this->variables[$alias] = $trimmed;
            }
        }

        if (in_array($cleanKey, self::EMAIL_ALIASES, true) && is_string($value) && trim($value) !== '') {
            $trimmed = trim($value);
            foreach (self::EMAIL_ALIASES as $alias) {
                $this->variables[$alias] = $trimmed;
            }
        }

        if (in_array($cleanKey, self::PHONE_ALIASES, true) && is_string($value) && trim($value) !== '') {
            $trimmed = trim($value);
            foreach (self::PHONE_ALIASES as $alias) {
                $this->variables[$alias] = $trimmed;
            }
        }

        return $this;
    }

    public function has(string $key): bool
    {
        $cleanKey = ltrim(trim($key), '$');

        if (in_array($cleanKey, ['system.agents_online', 'agents_online', 'system.agents_count', 'agents_count'], true)) {
            return true;
        }

        if (str_starts_with($cleanKey, 'contact.')) {
            $val = $this->get($cleanKey);

            return $val !== null && $val !== '';
        }

        if (in_array($cleanKey, self::NAME_ALIASES, true)) {
            $val = $this->get($cleanKey);

            return $val !== null && $val !== '';
        }

        if (in_array($cleanKey, self::EMAIL_ALIASES, true) || in_array($cleanKey, self::PHONE_ALIASES, true)) {
            $val = $this->get($cleanKey);

            return $val !== null && $val !== '';
        }

        return data_get($this->variables, $cleanKey) !== null || data_get($this->variables, $key) !== null;
    }

    public function remove(string $key): self
    {
        $cleanKey = ltrim(trim($key), '$');
        data_forget($this->variables, $cleanKey);
        data_forget($this->variables, $key);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->variables;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->variables;
    }
}
