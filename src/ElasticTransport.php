<?php


namespace FlexFlux\LaravelElasticEmail;

use Symfony\Component\Mime\Email;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class ElasticTransport extends AbstractTransport
{
    protected $key;
    protected $url = "https://api.elasticemail.com/v4/emails";

    public function __construct($key)
    {
        parent::__construct();

        $this->key = $key;
    }

    public function __toString(): string
    {
        return 'elasticemail';
    }

     /**
     * {@inheritdoc}
     */
    public function doSend(SentMessage $message) : void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $recipients = [
            'To' => $this->getEmailAddresses($email, 'getTo'),
            'CC' => $this->getEmailAddresses($email, 'getCc'),
            'BCC' => $this->getEmailAddresses($email, 'getBcc'),
        ];

        // Filter out empty arrays
        $recipients = array_filter($recipients, function($a) {
            return !empty($a);
        });

        $content = [
            'Body' => [],
            'From' => $email->getFrom()[0]->getAddress(),
            'ReplyTo' => $this->getEmailAddresses($email, 'getReplyTo') ? $this->getEmailAddresses($email, 'getReplyTo')[0] : null,
            'Subject' => $email->getSubject(),
        ];

        if ($email->getHtmlBody()) {
            $content['Body'][] = [
                'ContentType' => 'HTML',
                'Content' => $email->getHtmlBody(),
            ];
        }

        if ($email->getTextBody()) {
            $content['Body'][] = [
                'ContentType' => 'PlainText',
                'Content' => $email->getTextBody(),
            ];
        }
        
        // Handle FromName if present (v4 usually puts it in From like "Name <email>" or separate fields? Docs say From is string email. 
        // Checking v4 docs again conceptually, EnvelopeFrom is usually separate. 
        // But commonly APIs accept "Name <email>". Let's stick to simple email for From as per typical REST APIs unless docs said otherwise.
        // Actually, previous code handled 'msgFromName'. v4 `Content` object typically has `EnvelopeFrom` or expects `From` to be just email.
        // Wait, v4 spec `EmailContent` has `From` (string). Let's assume just email address for safety or "Name <email>" string.
        // Let's format it as "Name <email>" if name exists.
        $from = $email->getFrom()[0];
        if ($from->getName()) {
            $content['From'] = sprintf('%s <%s>', $from->getName(), $from->getAddress());
        }

        $attachments = $email->getAttachments();
        if (count($attachments) > 0) {
            $content['Attachments'] = $this->getAttachments($attachments);
        }

        $payload = [
            'Recipients' => $recipients,
            'Content' => $content,
        ];

        // Transactional header check
        // v4 might not support arbitrary headers in the same way or expects them in 'Headers' dict.
        // Let's check headers.
        /* 
        $headers = [];
        foreach ($email->getHeaders()->all() as $header) {
             // ... extract custom headers if needed
        }
        */

        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'X-ElasticEmail-ApiKey: ' . $this->key
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new \Exception('Elastic Email API URL Error: ' . $response);
        }
    }

    /**
     * Get attachments in v4 format.
     * @param $attachments
     * @return array
     */
    protected function getAttachments($attachments)
    {
        $data = [];
        foreach ($attachments as $attachment) {
            if ($attachment instanceof DataPart) {
                $filename = $attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename');
                $data[] = [
                    'BinaryContent' => base64_encode($attachment->getBody()),
                    'Name' => $filename,
                    'ContentType' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype()
                ];
            }
        }
        return $data;
    }

    /**
     * Retrieve requested email addresses from email.
     * @param Email $email
     * @param string $method
     * @return array
     */
    protected function getEmailAddresses(Email $email, $method = 'getTo')
    {
        $data = call_user_func([$email, $method]);

        $addresses = [];
        if (is_array($data)) {
            foreach ($data as $address) {
                $addresses[] = $address->getAddress();
            }
        }
        return $addresses;
    }
}
