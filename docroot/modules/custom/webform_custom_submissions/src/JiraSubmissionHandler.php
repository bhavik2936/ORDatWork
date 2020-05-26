<?php

namespace Drupal\webform_custom_submissions;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use \Exception;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystem;

class JiraSubmissionHandler {

  protected $create_issue_url;
  protected $domestic_project;
  protected $international_project;
  protected $submission_client;
  protected $username;
  protected $vouchers_project;
  protected $field_helper;

  public function __construct($config) {
    $travel_services_config = $config->get('webform_custom_submissions.form');
    $raw_username = $travel_services_config->get('USERNAME');
    $issue_creation_url = $travel_services_config->get('CREATE_ISSUE_URL');

    $this->create_issue_url = $travel_services_config->get('CREATE_ISSUE_URL');
    $this->domestic_project = $travel_services_config->get('DOMESTIC_PROJECT');
    $this->international_project = $travel_services_config->get('INTERNATIONAL_PROJECT');
    $this->submission_client = new Client(['base_uri' => $issue_creation_url]);
    $this->username = explode(':', $raw_username);
    $this->vouchers_project = $travel_services_config->get('VOUCHERS_PROJECT');
    $this->field_helper = new FieldHelper();
  }

  /*
   * Script for getting, formatting and submitting travel services form data to JIRA
   */

  public function submitToJira(WebformSubmissionInterface $webform_submission) {
    try {
      $fieldHelper = new FieldHelper();
      $fieldHelper->prepareFormData($webform_submission);
      $fieldHelper->prepareJiraData();
      if ($fieldHelper->isInternational()) {
        $fieldHelper->setProjectID($this->international_project);
      } else if ($fieldHelper->isVoucher()) {
        $fieldHelper->setProjectID($this->vouchers_project);
      } else {
        $fieldHelper->setProjectID($this->domestic_project);
      }
      $fieldHelper->setIssueType();
      $jira_data = $fieldHelper->getJiraData();
      $postData = $this->compilePOSTData($jira_data);
      $jira_data['fields']['summary'] = $this->getSummary($webform_submission, $jira_data);
      $postResponse = $this->sendPOSTData($postData);
      $decoded_response = json_decode($postResponse, TRUE);
      $issueId = $this->getIssueId($postResponse);
      $filesUploaded = $this->attachFiles($issueId, $jira_data);
      if (isset($decoded_response['errorMessages'])) {
        \Drupal::logger('Travel Services Error')->error($postResponse);
        drupal_set_message(t('There was an error processing your request. Code-0001'), 'error');
      } else if (!isset($decoded_response['id'])) {
        drupal_set_message(t('There was an error processing your request. Code-0002'), 'error');
        \Drupal::logger('Travel Services Error')->error('Unidentified Error: JIRA Response = ' . $postResponse);
      }
    } catch (Exception $e) {
      \Drupal::logger('Travel Services Exception')->error($e->getMessage());
      drupal_set_message(t('Unable to process request at this time, please try again later.'), 'error');
    }
  }

  public function getSummary(WebformSubmissionInterface $webform_submission, $jira_data) {
    $title = $webform_submission->getWebform()->get('title');
    $name = '';
    if (!empty($jira_data['customfield_10090'])) {
      $name = $jira_data['customfield_10090'];
    } else if (!empty($jira_data['customfield_10331'])) {
      $name = $jira_data['customfield_10331'];
    }
    return $title . ': ' . $name;
  }


  //Compiles POST data and returns the data formatted as JSON
  protected function compilePOSTData($form_data) {

    $dropDowns = $this->field_helper->get_dropdown_fields();

    $data = array('fields' => array());

    //Add POST variables to the array
    foreach ($form_data as $key => $val) {
      // ignore file uploads, we are handling these later
      if ($val === 'file') {
        continue;
      }

      if ($key == 'customfield_10191') {
        $data['fields'][$key] = array('value' => $val);
      }
      elseif ($key == 'customfield_10093') {
        $data['fields'][$key] = array('value' => $val);
      }
    //Capture dropdowns and turn them into arrays
      elseif (in_array($key, $dropDowns)) {
        //ignore time dropdowns if no time is selected
        if (($key == 'customfield_10322' ||
            $key == 'customfield_10320' ||
            $key == 'customfield_10324' ||
            $key == 'customfield_10316' ||
            $key == 'customfield_10302' ||
            $key == 'customfield_10318') && $val == 'none'
        ) {
          //do nothing
        } else {
          $data['fields'][$key] = array('value' => $val);
        }
      } //Capture Checkboxes and turn them into arrays
      elseif ($this->field_helper->is_checkbox_field($key)) {
        $checkboxArray = array();
        foreach ($val as $field2 => $value2) {
          array_push($checkboxArray, array('value' => $value2));
        }
        $data['fields'][$key] = $checkboxArray;
      }
      //Drupal 7 FAPI #states property does not currently support 'OR'
      //This workaround allows us to hide, show elements on 'OR' - These are fields in Travel Authorization
      elseif ($key == 'customfield_10431') {
        $data['fields']['customfield_10431'] = array('value' => $val);
      }
      //Drupal 7 FAPI #states property does not currently support 'OR'
      //This workaround allows us to hide, show elements on 'OR' - These are fields in Travel Authorization
      elseif ($key == 'customfield_10105a' ||
        $key == 'customfield_10105b' ||
        $key == 'customfield_10105c' && $val != ''
      ) {
        $data['fields']['customfield_10105'] = $val;
      } //Capture the rest of the customfield POST variables
      else {
        $data['fields'][$key] = $val;
      }
    }//end foreach

    //Set the Summary field
    if ($form_data['proxy'] == 'Yes')
      $data['fields']['summary'] = $form_data['formTitle'] . ': ' . $data['fields']['customfield_10331'];
    else if ($form_data['proxy'] == 'No')
      $data['fields']['summary'] = $form_data['formTitle'] . ': ' . $data['fields']['customfield_10090'];

    if ($form_data['customfield_10431'] == 'Yes')
      $data['fields']['customfield_10431'] = array('value' => 'Yes');

    $data['fields']['project'] = $form_data['fields']['project'];
    $data['fields']['issuetype'] = $form_data['fields']['issuetype'];
    $data['fields']['summary'] = $form_data['fields']['summary'];
    $jsonData = json_encode($data);
    return $jsonData;
  }

  /**
   * Builds and sends cURL request for the form POST data
   * @param $jsonData
   * @return Exception|\Psr\Http\Message\ResponseInterface
   */
  protected function sendPOSTData($jsonData) {
    try {
      $response = $this->submission_client->request('POST',
        $this->create_issue_url,
        ['json' => $jsonData, 'Content-Type' => "application/json",
          'auth' => ["{$this->username[0]}", "{$this->username[1]}"]]);

      return $response;
    } catch (Exception $e) {
      return new Exception($e->getMessage());
    }
  }

  //Extracts the issue id from the server response so we can submit file attachment
  protected function getIssueId($serverReponse) {
    $responseArray = json_decode($serverReponse);
    try {
      return $responseArray['id'];
    } catch (Exception $e) {
      throw new Exception($e->getMessage());
    }
  }

  //Attaches files to issue
  protected function attachFiles($id, $form_data) {

    $url = $this->create_issue_url . $id . '/attachments/';

    $header = array(
      'auth' => ["{$this->username[0]}", "{$this->username[1]}"],
      'X-Atlassian-Token' => "nocheck"
    );

    $fileNames = array();
    foreach ($form_data as $key => $files) {
      if (FieldHelper::isFile($key)) {
        foreach ($files as $fid) {
          $fileData = array('size' => 0);
          $file = File::load($fid);
          if (is_object($file)) {
            $fileData = array(
              'tmp_name' => \Drupal::service('file_system')->realpath($file->getFileUri()),
              'name' => $file->getFilename(),
              'size' => intval($file->getSize()),
              'mime' => $file->getMimeType(),
            );
          }

          if ($fileData['size'] > 0) {
            $response = $this->submission_client->request('POST',
              $url,
              ['headers' => $header,
                'multipart' => [
                  'name' => $fileData['tmp_name'],
                  'contents' => $fileData['mime'],
                  'filename' => $fileData['name']
                ]]);
            $decodedResponse = json_decode($response, TRUE);
            \Drupal::logger('Travel Services File Response')->notice($response);
            \Drupal::logger('Travel Services File Response')->notice($response);
            if (sizeof($decodedResponse) > 0) {
              $fileNames[] = $$fileData['name'];
            }
          }
        }
      }
    }
    return $fileNames;
  }
}