<?php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

if (!class_exists(\WP_Upgrader_Skin::class)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
}

/**
 * Silent upgrader skin that records every feedback() and error() call.
 *
 * We need a skin to pass to WP's Plugin_Upgrader, but we don't want it to
 * echo HTML to the request body (Plugin_Upgrader's default skin does that
 * because it expects to run inside wp-admin). This subclass collects each
 * message into an in-memory array so the caller can fish out the last error
 * and surface it to the dashboard.
 */
final class CapturingUpgraderSkin extends \WP_Upgrader_Skin
{
    /** @var list<string> */
    private array $messages = [];

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param string|\WP_Error $feedback
     */
    public function feedback($feedback, ...$args): void
    {
        if ($feedback instanceof \WP_Error) {
            foreach ($feedback->get_error_messages() as $message) {
                $this->messages[] = (string) $message;
            }
            return;
        }
        if (!is_string($feedback) || $feedback === '') {
            return;
        }
        $this->messages[] = $args === [] ? $feedback : vsprintf($feedback, $args);
    }

    /**
     * @param string|\WP_Error $errors
     */
    public function error($errors): void
    {
        if ($errors instanceof \WP_Error) {
            foreach ($errors->get_error_messages() as $message) {
                $this->errors[] = (string) $message;
            }
            return;
        }
        if (is_string($errors) && $errors !== '') {
            $this->errors[] = $errors;
        }
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function lastErrorMessage(): ?string
    {
        if ($this->errors === []) {
            return null;
        }
        return $this->errors[array_key_last($this->errors)];
    }
}
