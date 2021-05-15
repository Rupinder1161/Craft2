<?php
namespace modules;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\helpers\ElementHelper;
use craft\mail\Message;
use craft\services\Elements;
use craft\services\EntryRevisions;
use yii\base\Event;

/**
 * Custom module class.
 *
 * This class will be available throughout the system via:
 * `Craft::$app->getModule('my-module')`.
 *
 * You can change its module ID ("my-module") to something else from
 * config/app.php.
 *
 * If you want the module to get loaded on every request, uncomment this line
 * in config/app.php:
 *
 *     'bootstrap' => ['my-module']
 *
 * Learn more about Yii module development in Yii's documentation:
 * http://www.yiiframework.com/doc-2.0/guide-structure-modules.html
 */
class Module extends \yii\base\Module
{
    private $email_sent = false;

    /**
     * Initializes the module.
     */
    public function init()
    {
        // Set a @modules alias pointed to the modules/ directory
        Craft::setAlias('@modules', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\console\\controllers';
        } else {
            $this->controllerNamespace = 'modules\\controllers';
        }

        parent::init();

        // add neo graphql schema
        Event::on(
            \benf\neo\Field::class,
            'craftQlGetFieldSchema',
            [NeoCraftQLGetFieldSchema::class, 'handle']
        );

        // send emails after contact submission created
        Craft::$app->elements->on(Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $e) {

            $element = $e->element;
            if (ElementHelper::isDraftOrRevision($element) || !$element instanceof Entry || $this->email_sent) {
                return;
            }
            $contactSubmissionSectionID = (new Query())
                ->select(['sections.id'])
                ->from(['sections' => Table::SECTIONS])
                ->where(['sections.handle' => 'contactSubmission'])
                ->scalar();

            if ($contactSubmissionSectionID == $element->sectionId && !$element->getFieldValue('emailSent')) {
                $this->email_sent = true;

                $email = $element->getFieldValue('email');
                $name = $element->getFieldValue('fullName');
                $content = $element->getFieldValue('textContent');
                $phone = $element->getFieldValue('phoneNumber');

                ob_start();
                $ch = curl_init('https://baytechhomesendemail.azurewebsites.net/api/SendEmail');
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'message' => $content,
                ]));

                $output = curl_exec($ch);
                $response = curl_getinfo($ch);

                curl_close($ch);
                ob_get_clean();

                $mailer = Craft::$app->getMailer();

                $message = (new Message())
                    ->setFrom($mailer->from)
                    ->setReplyTo([$email => $name])
                    ->setSubject('Contact form submission | Bay Tech Consulting')
                    ->setHtmlBody(<<<HTML
    <p>Hello,</p>
    <p>Here is the contact form submission.</p>
    <table>
        <tr>
            <td><strong>Email</strong></td>
            <td>: $email</td>
        </tr>
        <tr>
            <td><strong>Name</strong></td>
            <td>: $name</td>
        </tr>
        <tr>
            <td><strong>Phone</strong></td>
            <td>: $phone</td>
        </tr>
        <tr>
            <td><strong>Message</strong></td>
            <td>: $content</td>
        </tr>
    </table>
    <p>Thanks</p>
HTML
);

                $set = Craft::$app->getGlobals()->getSetByHandle('site');
                $toEmails = $set->getFieldValue('email');
                if ($toEmails) {
                    $toEmails = array_map('trim', explode(',', $toEmails));
                    foreach ($toEmails as $toEmail) {
                        $message->setTo($toEmail);
                        $mailer->send($message);
                    }

                    $element->setFieldValue('emailSent', true);
                    Craft::$app->getElements()->saveElement($element);
                }
            }

        });

    }
}
