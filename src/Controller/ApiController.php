<?php

namespace App\Controller;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ApiController
{
    private $parameterBag;
    private $csvFilePath;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $this->parameterBag = $parameterBag;
        $this->csvFilePath = __DIR__ . '/../../db/emails.csv';
    }


    #[Route('/api/rate', name: 'get_rate')]
    public function getRate(): JsonResponse
    {
        // Retrieve the current BTC to UAH rate from a third-party service
        $rate = $this->fetchBTCRateFromThirdParty();

        // Check if the rate is valid
        if ($rate === 0.0) {
            // Return a JSON response with the error message and status code 400
            return new JsonResponse(['error' => 'Invalid status value'], Response::HTTP_BAD_REQUEST);
        }
        
        // Return a JSON response with the rate and status code 200
        return new JsonResponse($rate, Response::HTTP_OK);
    }


    #[Route('/api/subscribe', name: 'subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        // Get the email from the request parameters
        $email = $request->request->get('email');

        if (!$email) {
            // Return a JSON response with the error message and status code 400
            return new JsonResponse(['error' => 'No email provided'], Response::HTTP_BAD_REQUEST);
        }

        // Check if the email is already in the CSV file
        if ($this->isEmailInCSV($email)) {
            // Return a JSON response with the status code 409
            return new JsonResponse(['error' => 'Email already exists'], Response::HTTP_CONFLICT);
        }

        // Store the email in the CSV file
        $this->storeEmailInCSV($email);

        // Return a JSON response with the status code 200
        return new JsonResponse(['message' => 'Email added'], Response::HTTP_OK);
    }

    #[Route('/api/sendEmails', name: 'send_emails', methods: ['POST'])]
    public function sendEmails(MailerInterface $mailer): JsonResponse
    {
        // Retrieve the current BTC to UAH rate from a third-party service
        $rate = $this->fetchBTCRateFromThirdParty();

        // Logic to retrieve subscribed email addresses
        $subscribedEmails = $this->retrieveSubscribedEmails();

        // Perform necessary operations to send emails to subscribed addresses
        foreach ($subscribedEmails as $email) {
            $this->sendEmail($mailer, $email, $rate);
        }

        // Return a JSON response with the status code 200
        return new JsonResponse(['message' => 'Emails sent'], JsonResponse::HTTP_OK);
    }

    private function fetchBTCRateFromThirdParty(): float
    {
        $accessKey = $this->parameterBag->get('coinlayer_access_key');

        $httpClient = HttpClient::create();
        $url = 'http://api.coinlayer.com/api/live';
        $target = 'UAH';
        $symbols = 'BTC';

        $response = $httpClient->request('GET', $url, [
            'query' => [
                'access_key' => $accessKey,
                'target' => $target,
                'symbols' => $symbols,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $content = $response->toArray();

        if ($statusCode !== 200) {
            return 0;
        }
        return $content['rates']['BTC'];
    }

    // Helper method to check if the email is in the CSV file
    private function isEmailInCSV(string $email): bool
    {
        if (!file_exists($this->csvFilePath)) {
            return false;
        }

        $csvData = array_map('str_getcsv', file($this->csvFilePath));
        $emails = array_column($csvData, 0);

        return in_array($email, $emails);
    }

    private function storeEmailInCSV(string $email): void
        {
            $csvData = [$email];
            $csvFile = $this->csvFilePath;

            $filesystem = new Filesystem();
            if (!$filesystem->exists($csvFile)) {
                // Create the directory if it doesn't exist
                $filesystem->mkdir(dirname($csvFile));
            }

            // Open the file in append mode or create it if it doesn't exist
            $file = fopen($csvFile, 'a+');

            // Lock the file for writing
            flock($file, LOCK_EX);

            // Move the file pointer to the end of the file
            fseek($file, 0, SEEK_END);

            // Write the data to the file
            fputcsv($file, $csvData);

            // Release the lock and close the file
            flock($file, LOCK_UN);
            fclose($file);
        }

    private function retrieveSubscribedEmails(): array
    {
        // Logic to read the 'emails.csv' file and retrieve subscribed email addresses
        $csvFilePath = __DIR__ . '/../../db/emails.csv';
        $emails = [];

        if (($handle = fopen($csvFilePath, 'r')) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $emails[] = $data[0];
            }
            fclose($handle);
        }

        return $emails;
    }

    private function sendEmail(MailerInterface $mailer, string $recipient, float $rate): void
    {
        // Prepare the email
        $email = (new Email())
            ->from('testCase@genesis.ua')
            ->to(new Address($recipient))
            ->subject('Current BTC to UAH Rate')
            ->text("The current rate of BTC to UAH is: $rate");

        // Send the email
        $mailer->send($email);
    }
}
