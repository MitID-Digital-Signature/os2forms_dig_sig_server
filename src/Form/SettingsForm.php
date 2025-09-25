<?php

namespace Drupal\os2forms_dig_sig_server\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Signing settings form.
 */
class SettingsForm extends ConfigFormBase {
  use StringTranslationTrait;

  /**
   * Name of the config.
   *
   * @var string
   */
  public static $configName = 'os2forms_dig_sig_server.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'os2forms_dig_sig_server_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::$configName];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['signing_service_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Signing server URL'),
      '#default_value' => $this->config(self::$configName)->get('signing_service_url'),
      '#description' => $this->t('E.g. https://signering.bellcom.dk'),
      '#required' => TRUE,
    ];

    $form['allowed_domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed domains'),
      '#default_value' => $this->config(self::$configName)->get('allowed_domains'),
      '#description' => $this->t('CSV list of allowed domain. E.g. sign.localhost,sign.local,localhost,example.vhost.com'),
    ];

    $form['working_dir'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Files working directory'),
      '#default_value' => $this->config(self::$configName)->get('working_dir'),
      '#description' => $this->t('Directory where the source and signed PDF files will be stored. For security reasons private:// or a path outside Drupal web is recommended'),
      '#required' => TRUE,
    ];

    $form['signing_hash_salt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hash Salt used for signature'),
      '#default_value' => $this->config(self::$configName)->get('signing_hash_salt'),
      '#description' => $this->t('Must match hash salt on the signature client.'),
      '#required' => TRUE,
    ];

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#default_value' => $this->config(self::$configName)->get('debug_mode'),
      '#description' => $this->t('When debug mode is on, operational debug messages will be stored in watchdog'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Removing slash from URL.
    $values['signing_service_url'] = rtrim($values['signing_service_url'], '/\\');

    // Removing slash at the end of the string.
    $values['working_dir'] = rtrim($values['working_dir'], '/\\');

    $config = $this->config(self::$configName);
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
