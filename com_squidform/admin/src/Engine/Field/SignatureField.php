<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use SquidForm\Component\SquidForm\Administrator\Engine\ESign;
use SquidForm\Component\SquidForm\Administrator\Engine\Html;

\defined('_JEXEC') or die;

/**
 * A signature (0.8.1): draw it on a pad, or type your name.
 *
 * "modes": ["draw", "type"] (default both). Drawing needs a mouse, finger or
 * pen, so typing is always there for people who can't draw, and it's what
 * works without JavaScript. "statement" is the text people agree to by
 * signing ("I confirm that the information above is correct.").
 *
 * Stored as {"mode": "draw", "png": "data:image/png;base64,…", "name": "…"}
 * or {"mode": "type", "name": "Pat Example"}, plus "at" (UTC, when it was
 * signed). The server accepts only a real PNG of at most 200 KB and
 * 1600 × 800 pixels; anything else is dropped.
 *
 * An e-signature (0.11.0, an "esign" block; see ESign) adds: signed-in
 * signers only, the optional consumer disclosure with its own tick box, and
 * the statement as a tick box ("I agree to sign this electronically"), stored
 * as "intent" (and "consent") in the answer.
 */
final class SignatureField extends AbstractField
{
    public const MAX_BYTES = 204800;

    public static function modes(array $field): array
    {
        $m = array_values(array_intersect(['draw', 'type'], \is_array($field['modes'] ?? null) ? $field['modes'] : ['draw', 'type']));

        return $m ?: ['draw', 'type'];
    }

    public function render(array $field, mixed $value, RenderContext $rc): string
    {
        $key    = $field['key'];
        $id     = $rc->id($key);
        $name   = $rc->name($key);
        $modes  = self::modes($field);
        $v      = \is_array($value) ? $value : [];
        $err    = $rc->error($key) !== null;
        $db     = self::describedBy($field, $rc);
        $locked = isset($rc->locked[$key]) || !empty($field['readonly']);
        $html   = '';
        $esign  = ESign::is($field);

        if ($esign) {
            $user = Factory::getApplication()->getIdentity();

            // Only signed-in people can e-sign: a link to sign in and come back.
            if (!$user || (int) $user->id <= 0) {
                $login = Route::_('index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString()), false);

                return self::wrap($field, $rc, '<div class="alert alert-info mb-0" id="' . $id . '-signin">' . self::e(Text::_('COM_SQUIDFORM_ESIGN_SIGN_IN'))
                    . ' <a href="' . self::e($login) . '">' . self::e(Text::_('COM_SQUIDFORM_ESIGN_SIGN_IN_LINK')) . '</a></div>', true, 'sf-signature sf-esign');
            }

            $cfg = ESign::config($field);

            if ($cfg['disclosure'] !== '') {
                $html .= '<div class="sf-esign-disclosure border rounded p-2 mb-2" tabindex="0" role="region" aria-label="' . self::e(Text::_('COM_SQUIDFORM_ESIGN_DISCLOSURE')) . '">' . Html::clean($cfg['disclosure']) . '</div>'
                    . '<div class="form-check mb-2"><input class="form-check-input' . ($err ? ' is-invalid' : '') . '" type="checkbox" id="' . $id . '-agree" name="' . self::e($name . '[consent]') . '" value="1"'
                    . (!empty($v['consent']) ? ' checked' : '') . ($locked ? ' disabled' : '') . '>'
                    . '<label class="form-check-label" for="' . $id . '-agree">' . self::e(Text::_('COM_SQUIDFORM_ESIGN_CONSENT_LABEL')) . '</label></div>';
            }

            $html .= '<p class="small text-body-secondary mb-1">' . self::e(Text::sprintf('COM_SQUIDFORM_ESIGN_SIGNING_AS', (string) $user->name, (string) $user->username)) . '</p>';
        }

        if (!empty($field['statement'])) {
            $html .= '<p class="sf-sig-statement" id="' . $id . '-statement">' . self::e($field['statement']) . '</p>';
            $db = trim($id . '-statement ' . $db);
        }

        if (\in_array('draw', $modes, true)) {
            $png = (string) ($v['png'] ?? '');

            // Shown by squidform.js; without JavaScript only typing works.
            $html .= '<div class="sf-sig-pad" data-sf-signature="' . $id . '" hidden>'
                . '<p class="form-text mb-1" id="' . $id . '-padhelp">' . self::e(Text::_(\in_array('type', $modes, true) ? 'COM_SQUIDFORM_SIG_DRAW_OR_TYPE' : 'COM_SQUIDFORM_SIG_DRAW')) . '</p>'
                . '<canvas class="sf-sig-canvas' . ($err ? ' is-invalid' : '') . '" width="600" height="200" role="img" aria-label="' . self::e(Text::_('COM_SQUIDFORM_SIG_PAD')) . '"'
                . ' aria-describedby="' . $id . '-padhelp ' . $id . '-padstate"' . ($png !== '' ? ' data-sf-initial="' . self::e($png) . '"' : '') . '></canvas>'
                . '<div class="d-flex align-items-center gap-2 mt-1">'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-sf-sig-clear>' . self::e(Text::_('COM_SQUIDFORM_SIG_CLEAR')) . '</button>'
                . '<span class="small text-body-secondary" id="' . $id . '-padstate" aria-live="polite">' . ($png !== '' ? self::e(Text::_('COM_SQUIDFORM_SIG_DRAWN')) : '') . '</span>'
                . '</div>'
                . '<input type="hidden" name="' . self::e($name . '[png]') . '" value="' . self::e($png) . '" data-sf-sig-png' . ($locked ? ' disabled' : '') . '>'
                . '</div>';
        }

        if (\in_array('type', $modes, true)) {
            $tid   = $id . '-name';
            $only  = $modes === ['type'];
            $html .= '<div class="sf-sig-type mt-2">'
                . '<label class="form-label' . ($only ? '' : ' small') . '" for="' . $tid . '">' . self::e(Text::_($only ? 'COM_SQUIDFORM_SIG_TYPE_ONLY' : 'COM_SQUIDFORM_SIG_TYPE')) . '</label>'
                . '<input type="text" class="form-control sf-sig-name' . ($err ? ' is-invalid' : '') . '" id="' . $tid . '" name="' . self::e($name . '[name]') . '"'
                . ' value="' . self::e((string) ($v['name'] ?? '')) . '" maxlength="200" autocomplete="name"'
                . ($db !== '' ? ' aria-describedby="' . $db . '"' : '') . ($err ? ' aria-invalid="true"' : '')
                . ($locked ? ' readonly aria-readonly="true"' : '') . '>'
                . '</div>';
        }

        if ($esign) {
            $html .= '<div class="form-check mt-2 sf-esign-intent"><input class="form-check-input' . ($err ? ' is-invalid' : '') . '" type="checkbox" id="' . $id . '-intent" name="' . self::e($name . '[intent]') . '" value="1"'
                . (!empty($v['intent']) ? ' checked' : '') . ($locked ? ' disabled' : '') . '>'
                . '<label class="form-check-label fw-semibold" for="' . $id . '-intent">' . self::e(ESign::config($field)['statement']) . '</label></div>';
        }

        return self::wrap($field, $rc, $html, true, $esign ? 'sf-signature sf-esign' : 'sf-signature');
    }

    public function normalize(array $field, mixed $raw): mixed
    {
        $raw   = \is_array($raw) ? $raw : [];
        $modes = self::modes($field);
        $name  = \in_array('type', $modes, true) || \in_array('draw', $modes, true)
            ? mb_substr(trim(preg_replace('/\s+/u', ' ', \is_scalar($raw['name'] ?? null) ? (string) $raw['name'] : '') ?? ''), 0, 200)
            : '';
        $png   = \in_array('draw', $modes, true) && \is_string($raw['png'] ?? null) ? self::cleanPng($raw['png']) : '';
        $at    = gmdate('Y-m-d\TH:i:s\Z');
        $extra = [];

        // E-signature (0.11.0): the tick boxes, as true/false.
        if (ESign::is($field)) {
            $extra['intent'] = ($raw['intent'] ?? '') === '1';

            if (ESign::config($field)['disclosure'] !== '') {
                $extra['consent'] = ($raw['consent'] ?? '') === '1';
            }
        }

        if ($png !== '') {
            return ['mode' => 'draw', 'png' => $png] + ($name !== '' ? ['name' => $name] : []) + ['at' => $at] + $extra;
        }

        if ($name !== '' && \in_array('type', $modes, true)) {
            return ['mode' => 'type', 'name' => $name, 'at' => $at] + $extra;
        }

        return [];
    }

    /** A data: URL holding a real, reasonably sized PNG, or ''. */
    public static function cleanPng(string $url): string
    {
        if (!str_starts_with($url, 'data:image/png;base64,') || \strlen($url) > self::MAX_BYTES * 4 / 3 + 64) {
            return '';
        }

        $bin = base64_decode(substr($url, 22), true);

        if ($bin === false || \strlen($bin) > self::MAX_BYTES || !str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
            return '';
        }

        $info = @getimagesizefromstring($bin);

        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG || $info[0] < 20 || $info[1] < 10 || $info[0] > 1600 || $info[1] > 800) {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($bin);
    }

    public function validate(array $field, mixed $value, array $scope, ValidateContext $vc): array
    {
        $label = (string) ($field['label'] ?? $field['key']);

        if (ESign::is($field)) {
            $user = Factory::getApplication()->getIdentity();

            if (!$user || (int) $user->id <= 0) {
                return [$vc->path($field['key']) => Text::_('COM_SQUIDFORM_ESIGN_SIGN_IN')];
            }

            if (!self::isEmpty($value)) {
                if (ESign::config($field)['disclosure'] !== '' && empty($value['consent'])) {
                    return [$vc->path($field['key']) => Text::sprintf('COM_SQUIDFORM_ESIGN_ERR_CONSENT_FIELD', $label)];
                }

                if (empty($value['intent'])) {
                    return [$vc->path($field['key']) => Text::sprintf('COM_SQUIDFORM_ESIGN_ERR_INTENT', $label)];
                }
            }
        }

        if (self::isEmpty($value) && self::required($field)) {
            $modes = self::modes($field);
            $msg   = $modes === ['draw'] ? 'COM_SQUIDFORM_ERR_SIG_DRAW' : ($modes === ['type'] ? 'COM_SQUIDFORM_ERR_SIG_TYPE' : 'COM_SQUIDFORM_ERR_SIG');

            return [$vc->path($field['key']) => Text::sprintf($msg, (string) ($field['label'] ?? $field['key']))];
        }

        return [];
    }

    /** "Signed by drawing (Pat Example), 2026-10-30 14:02 UTC" */
    public static function describe(array $v): string
    {
        $when = isset($v['at']) ? ' ' . Text::sprintf('COM_SQUIDFORM_SIG_AT', str_replace(['T', 'Z'], [' ', ''], substr((string) $v['at'], 0, 16) . 'Z')) : '';

        if (($v['mode'] ?? '') === 'draw') {
            return Text::_('COM_SQUIDFORM_SIG_SIGNED_DRAWN') . (!empty($v['name']) ? ' (' . $v['name'] . ')' : '') . $when;
        }

        return Text::sprintf('COM_SQUIDFORM_SIG_SIGNED_TYPED', (string) ($v['name'] ?? '')) . $when;
    }
}
