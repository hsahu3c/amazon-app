<?php

namespace App\Amazon\Components\SupplySource\Helpers;

use App\Core\Components\Base;

class UpdateHelper extends Base
{
    public function prepareSupplySourceUpdateData($data): array
    {
        if (empty($data['supply_source_id'])) {
            return ['success' => false, 'message' => 'supply_source_id is required'];
        }

        if (!empty($data['alias'])) {
            $preparedUpdateData['alias'] = trim($data['alias']);
        }

        if (isset($data['configuration'])) {
            $prepareConfigResp = $this->prepareConfiguration($data['configuration']);
            if (!$prepareConfigResp['success']) {
                return $prepareConfigResp;
            }
            $preparedUpdateData['configuration'] = $prepareConfigResp['data'];
        }

        if (empty($preparedUpdateData)) {
            return ['success' => false, 'message' => 'No data found to update'];
        }

        $preparedUpdateData['supplySourceId'] = trim(str_replace(' ', '', $data['supply_source_id']));

        return ['success' => true, 'data' => $preparedUpdateData];
    }

    private function prepareConfiguration($config): array
    {
        $configurationRequiredParams = ['operationalConfiguration', 'timezone'];

        $hasRequiredConfigurationParams = $this->hasRequiredParams($config, $configurationRequiredParams);
        if (!$hasRequiredConfigurationParams['success']) {
            return $hasRequiredConfigurationParams;
        }

        $prepareOperationalConfigRes = $this->prepareOperationalConfiguration($config['operationalConfiguration']);
        if (!$prepareOperationalConfigRes['success']) {
            return $prepareOperationalConfigRes;
        }

        $operationalConfiguration = $prepareOperationalConfigRes['data'];

        $prepareTimezoneRes = $this->prepareTimezone($config['timezone']);
        if (!$prepareTimezoneRes['success']) {
            return $prepareTimezoneRes;
        }

        $timezone = $prepareTimezoneRes['data'];

        return [
            'success' => true,
            'data' => compact('operationalConfiguration', 'timezone')
        ];
    }

    private function prepareOperationalConfiguration($operationalConfiguration)
    {
        $operationalConfigRequiredParams = ['contactDetails', 'throughputConfig', 'operatingHoursByDay', 'handlingTime'];

        $hasRequiredOperationalParams = $this->hasRequiredParams($operationalConfiguration, $operationalConfigRequiredParams);
        if (!$hasRequiredOperationalParams['success']) {
            return $hasRequiredOperationalParams;
        }

        $prepareContactDetailsResp = $this->prepareContactDetails($operationalConfiguration['contactDetails']);
        if (!$prepareContactDetailsResp['success']) {
            return $prepareContactDetailsResp;
        }

        $contactDetails = $prepareContactDetailsResp['data'];

        $prepareThroughputConfigRes = $this->prepareThroughputConfig($operationalConfiguration['throughputConfig']);
        if (!$prepareThroughputConfigRes['success']) {
            return $prepareThroughputConfigRes;
        }

        $throughputConfig = $prepareThroughputConfigRes['data'];

        $prepareOperatingHoursRes = $this->prepareOperatingHoursByDayConfig($operationalConfiguration['operatingHoursByDay']);
        if (!$prepareOperatingHoursRes['success']) {
            return $prepareOperatingHoursRes;
        }

        $operatingHoursByDay = $prepareOperatingHoursRes['data'];

        $prepareHandlingTimeRes = $this->prepareHandlingTime($operationalConfiguration['handlingTime']);
        if (!$prepareHandlingTimeRes['success']) {
            return $prepareHandlingTimeRes;
        }

        $handlingTime = $prepareHandlingTimeRes['data'];

        return [
            'success' => true,
            'data' => compact('contactDetails', 'throughputConfig', 'operatingHoursByDay', 'handlingTime')
        ];
    }

    private function prepareContactDetails($contact)
    {
        $requiredParams = ['phone', 'email'];
        $hasRequiredParams = $this->hasRequiredParams($contact['primary'], $requiredParams);
        if (!$hasRequiredParams['success']) {
            return $hasRequiredParams;
        }

        return [
            'success' => true,
            'data' => [
                'primary' => [
                    'phone' => $this->sanitizePhoneNumber($contact['primary']['phone'] ?? ''),
                    'email' => $this->sanitizeEmail($contact['primary']['email'] ?? '')
                ]
            ]
        ];
    }

    private function prepareThroughputConfig($throughputConfig)
    {
        $requiredParams = ['throughputCap', 'throughputUnit'];
        $hasRequiredParams = $this->hasRequiredParams($throughputConfig, $requiredParams);
        if (!$hasRequiredParams['success']) {
            return $hasRequiredParams;
        }

        if (!in_array($throughputConfig['throughputUnit'], ['Order'])) {
            return ['success' => false, 'message' => 'throughputUnit is not from allowed set of values'];
        }

        $throughputCapRquiredParams = ['timeUnit', 'value'];

        $hasRequiredThroughputCapParams = $this->hasRequiredParams($throughputConfig['throughputCap'], $throughputCapRquiredParams);

        if (!$hasRequiredThroughputCapParams) {
            return $hasRequiredThroughputCapParams;
        }

        if ($throughputConfig['throughputCap']['value'] < 0) {
            return ['sucess' => false, 'message' => 'throughputCap cannot be less than 0'];
        }

        return [
            'success' => true,
            'data' => [
                'throughputCap' => [
                    'value' => $throughputConfig['throughputCap']['value'],
                    'timeUnit' => $throughputConfig['throughputCap']['timeUnit']
                ],
                'throughputUnit' => $throughputConfig['throughputUnit']
            ]
        ];
    }

    private function prepareOperatingHoursByDayConfig($operatingHoursConfig)
    {
        if (!is_array($operatingHoursConfig)) {
            return ['success' => false, 'message' => 'operatingHoursByDay must be array'];
        }

        $allowedDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $preparedOperatingHoursConfig = [];

        foreach ($operatingHoursConfig as $day => $dayConfigs) {
            if (!in_array($day, $allowedDays)) {
                return ['success' => false, 'message' => 'Operating day - ' . ucfirst($day) . ' is not valid'];
            }

            foreach($dayConfigs as $dayConfig) {
                $dayConfigReqParams = ['startTime', 'endTime'];
                $hasRequiredDayConfig = $this->hasRequiredParams($dayConfig, $dayConfigReqParams);
                if (!$hasRequiredDayConfig['success']) {
                    return $hasRequiredDayConfig;
                }

                if (!$this->isValidIsoTime($dayConfig['startTime']) || !$this->isValidIsoTime($dayConfig['endTime'])) {
                    return ['success' => false, 'message' => 'Operating day - ' . ucfirst($day) .'\'s start and end time must be in ISO 8601 format'];
                }

                $preparedOperatingHoursConfig[$day][] = [
                    'startTime' => $dayConfig['startTime'],
                    'endTime' => $dayConfig['endTime']
                ];
            }
        }

        return ['success'=> true, 'data' => $preparedOperatingHoursConfig];
    }

    private function prepareHandlingTime($handlingTimeConfig)
    {
        $requiredParams = ['value', 'timeUnit'];
        $hasRequiredParams = $this->hasRequiredParams($handlingTimeConfig, $requiredParams);
        if (!$hasRequiredParams['success']) {
            return $hasRequiredParams;
        }

        return [
            'success' => true,
            'data' => [
                'value' => $handlingTimeConfig['value'],
                'timeUnit' => $handlingTimeConfig['timeUnit'],
            ]
        ];
    }

    private function prepareTimezone($timezoneConfig)
    {
        if (!$this->isValidCanonicalTimeZoneId($timezoneConfig)) {
            return ['success' => false, 'message' => 'Timezone should be from valid canonical time zone'];
        }

        return ['success' => true, 'data' => $timezoneConfig];
    }

    private function isValidCanonicalTimeZoneId(string $timeZoneId): bool
    {
        $validCanonicalTimeZones = [
            'Africa/Abidjan',
            'Africa/Accra',
            'Africa/Algiers',
            'Africa/Bissau',
            'Africa/Cairo',
            'Africa/Casablanca',
            'Africa/Ceuta',
            'Africa/El_Aaiun',
            'Africa/Johannesburg',
            'Africa/Juba',
            'Africa/Khartoum',
            'Africa/Lagos',
            'Africa/Maputo',
            'Africa/Monrovia',
            'Africa/Nairobi',
            'Africa/Ndjamena',
            'Africa/Sao_Tome',
            'Africa/Tripoli',
            'Africa/Tunis',
            'Africa/Windhoek',
            'America/Adak',
            'America/Anchorage',
            'America/Araguaina',
            'America/Argentina/Buenos_Aires',
            'America/Argentina/Catamarca',
            'America/Argentina/Cordoba',
            'America/Argentina/Jujuy',
            'America/Argentina/La_Rioja',
            'America/Argentina/Mendoza',
            'America/Argentina/Rio_Gallegos',
            'America/Argentina/Salta',
            'America/Argentina/San_Juan',
            'America/Argentina/San_Luis',
            'America/Argentina/Tucuman',
            'America/Argentina/Ushuaia',
            'America/Asuncion',
            'America/Atikokan',
            'America/Bahia',
            'America/Bahia_Banderas',
            'America/Barbados',
            'America/Belem',
            'America/Belize',
            'America/Blanc-Sablon',
            'America/Boa_Vista',
            'America/Bogota',
            'America/Boise',
            'America/Cambridge_Bay',
            'America/Campo_Grande',
            'America/Cancun',
            'America/Caracas',
            'America/Cayenne',
            'America/Chicago',
            'America/Chihuahua',
            'America/Costa_Rica',
            'America/Creston',
            'America/Cuiaba',
            'America/Danmarkshavn',
            'America/Dawson',
            'America/Dawson_Creek',
            'America/Denver',
            'America/Detroit',
            'America/Edmonton',
            'America/Eirunepe',
            'America/El_Salvador',
            'America/Fort_Nelson',
            'America/Fortaleza',
            'America/Glace_Bay',
            'America/Goose_Bay',
            'America/Grand_Turk',
            'America/Guatemala',
            'America/Halifax',
            'America/Havana',
            'America/Hermosillo',
            'America/Indiana/Indianapolis',
            'America/Indiana/Knox',
            'America/Indiana/Marengo',
            'America/Indiana/Petersburg',
            'America/Indiana/Vevay',
            'America/Indiana/Vincennes',
            'America/Indiana/Winamac',
            'America/Inuvik',
            'America/Iqaluit',
            'America/Jamaica',
            'America/Juneau',
            'America/Kentucky/Louisville',
            'America/Kentucky/Monticello',
            'America/La_Paz',
            'America/Lima',
            'America/Los_Angeles',
            'America/Maceio',
            'America/Managua',
            'America/Manaus',
            'America/Martinique',
            'America/Matamoros',
            'America/Mazatlan',
            'America/Menominee',
            'America/Merida',
            'America/Metlakatla',
            'America/Mexico_City',
            'America/Miquelon',
            'America/Moncton',
            'America/Monterrey',
            'America/Montevideo',
            'America/Nassau',
            'America/New_York',
            'America/Nipigon',
            'America/Nome',
            'America/Noronha',
            'America/North_Dakota/Beulah',
            'America/North_Dakota/Center',
            'America/North_Dakota/New_Salem',
            'America/Ojinaga',
            'America/Panama',
            'America/Paramaribo',
            'America/Phoenix',
            'America/Port-au-Prince',
            'America/Port_of_Spain',
            'America/Porto_Velho',
            'America/Puerto_Rico',
            'America/Punta_Arenas',
            'America/Rainy_River',
            'America/Rankin_Inlet',
            'America/Recife',
            'America/Regina',
            'America/Resolute',
            'America/Rio_Branco',
            'America/Santarem',
            'America/Santiago',
            'America/Sao_Paulo',
            'America/Scoresbysund',
            'America/Sitka',
            'America/St_Johns',
            'America/Swift_Current',
            'America/Tegucigalpa',
            'America/Thule',
            'America/Thunder_Bay',
            'America/Tijuana',
            'America/Toronto',
            'America/Vancouver',
            'America/Whitehorse',
            'America/Winnipeg',
            'America/Yakutat',
            'America/Yellowknife',
            'Antarctica/Casey',
            'Antarctica/Davis',
            'Antarctica/DumontDUrville',
            'Antarctica/Macquarie',
            'Antarctica/Mawson',
            'Antarctica/Palmer',
            'Antarctica/Rothera',
            'Antarctica/Syowa',
            'Antarctica/Troll',
            'Antarctica/Vostok',
            'Asia/Almaty',
            'Asia/Amman',
            'Asia/Anadyr',
            'Asia/Aqtau',
            'Asia/Aqtobe',
            'Asia/Ashgabat',
            'Asia/Atyrau',
            'Asia/Baghdad',
            'Asia/Baku',
            'Asia/Bangkok',
            'Asia/Barnaul',
            'Asia/Beirut',
            'Asia/Bishkek',
            'Asia/Brunei',
            'Asia/Chita',
            'Asia/Choibalsan',
            'Asia/Colombo',
            'Asia/Damascus',
            'Asia/Dhaka',
            'Asia/Dili',
            'Asia/Dubai',
            'Asia/Dushanbe',
            'Asia/Famagusta',
            'Asia/Gaza',
            'Asia/Hebron',
            'Asia/Ho_Chi_Minh',
            'Asia/Hong_Kong',
            'Asia/Hovd',
            'Asia/Irkutsk',
            'Asia/Jakarta',
            'Asia/Jayapura',
            'Asia/Jerusalem',
            'Asia/Kabul',
            'Asia/Kamchatka',
            'Asia/Karachi',
            'Asia/Kathmandu',
            'Asia/Khandyga',
            'Asia/Kolkata',
            'Asia/Krasnoyarsk',
            'Asia/Kuala_Lumpur',
            'Asia/Kuching',
            'Asia/Kuwait',
            'Asia/Macau',
            'Asia/Magadan',
            'Asia/Makassar',
            'Asia/Manila',
            'Asia/Muscat',
            'Asia/Nicosia',
            'Asia/Novokuznetsk',
            'Asia/Novosibirsk',
            'Asia/Omsk',
            'Asia/Oral',
            'Asia/Pontianak',
            'Asia/Pyongyang',
            'Asia/Qatar',
            'Asia/Qyzylorda',
            'Asia/Riyadh',
            'Asia/Sakhalin',
            'Asia/Samarkand',
            'Asia/Seoul',
            'Asia/Shanghai',
            'Asia/Singapore',
            'Asia/Srednekolymsk',
            'Asia/Taipei',
            'Asia/Tashkent',
            'Asia/Tbilisi',
            'Asia/Tehran',
            'Asia/Thimphu',
            'Asia/Tokyo',
            'Asia/Tomsk',
            'Asia/Ulaanbaatar',
            'Asia/Urumqi',
            'Asia/Ust-Nera',
            'Asia/Vladivostok',
            'Asia/Yakutsk',
            'Asia/Yangon',
            'Asia/Yekaterinburg',
            'Asia/Yerevan',
            'Atlantic/Azores',
            'Atlantic/Bermuda',
            'Atlantic/Canary',
            'Atlantic/Cape_Verde',
            'Atlantic/Faroe',
            'Atlantic/Madeira',
            'Atlantic/Reykjavik',
            'Atlantic/South_Georgia',
            'Atlantic/Stanley',
            'Atlantic/St_Helena',
            'Australia/Adelaide',
            'Australia/Brisbane',
            'Australia/Broken_Hill',
            'Australia/Darwin',
            'Australia/Eucla',
            'Australia/Hobart',
            'Australia/Lindeman',
            'Australia/Lord_Howe',
            'Australia/Melbourne',
            'Australia/Perth',
            'Australia/Sydney',
            'Etc/GMT',
            'Etc/GMT+1',
            'Etc/GMT+10',
            'Etc/GMT+11',
            'Etc/GMT+12',
            'Etc/GMT+2',
            'Etc/GMT+3',
            'Etc/GMT+4',
            'Etc/GMT+5',
            'Etc/GMT+6',
            'Etc/GMT+7',
            'Etc/GMT+8',
            'Etc/GMT+9',
            'Etc/GMT-1',
            'Etc/GMT-10',
            'Etc/GMT-11',
            'Etc/GMT-12',
            'Etc/GMT-13',
            'Etc/GMT-14',
            'Etc/GMT-2',
            'Etc/GMT-3',
            'Etc/GMT-4',
            'Etc/GMT-5',
            'Etc/GMT-6',
            'Etc/GMT-7',
            'Etc/GMT-8',
            'Etc/GMT-9',
            'Etc/UTC',
            'Europe/Amsterdam',
            'Europe/Andorra',
            'Europe/Astrakhan',
            'Europe/Athens',
            'Europe/Belgrade',
            'Europe/Berlin',
            'Europe/Brussels',
            'Europe/Bucharest',
            'Europe/Budapest',
            'Europe/Chisinau',
            'Europe/Copenhagen',
            'Europe/Dublin',
            'Europe/Gibraltar',
            'Europe/Helsinki',
            'Europe/Istanbul',
            'Europe/Kaliningrad',
            'Europe/Kiev',
            'Europe/Kirov',
            'Europe/Lisbon',
            'Europe/London',
            'Europe/Luxembourg',
            'Europe/Madrid',
            'Europe/Malta',
            'Europe/Minsk',
            'Europe/Monaco',
            'Europe/Moscow',
            'Europe/Oslo',
            'Europe/Paris',
            'Europe/Prague',
            'Europe/Riga',
            'Europe/Rome',
            'Europe/Samara',
            'Europe/Saratov',
            'Europe/Simferopol',
            'Europe/Sofia',
            'Europe/Stockholm',
            'Europe/Tallinn',
            'Europe/Tirane',
            'Europe/Ulyanovsk',
            'Europe/Uzhgorod',
            'Europe/Vienna',
            'Europe/Vilnius',
            'Europe/Volgograd',
            'Europe/Warsaw',
            'Europe/Zaporozhye',
            'Europe/Zurich',
            'Indian/Chagos',
            'Indian/Christmas',
            'Indian/Cocos',
            'Indian/Kerguelen',
            'Indian/Mahe',
            'Indian/Maldives',
            'Indian/Mauritius',
            'Indian/Reunion',
            'Pacific/Apia',
            'Pacific/Auckland',
            'Pacific/Bougainville',
            'Pacific/Chatham',
            'Pacific/Chuuk',
            'Pacific/Easter',
            'Pacific/Efate',
            'Pacific/Enderbury',
            'Pacific/Fakaofo',
            'Pacific/Fiji',
            'Pacific/Funafuti',
            'Pacific/Galapagos',
            'Pacific/Gambier',
            'Pacific/Guadalcanal',
            'Pacific/Guam',
            'Pacific/Honolulu',
            'Pacific/Kiritimati',
            'Pacific/Kosrae',
            'Pacific/Kwajalein',
            'Pacific/Majuro',
            'Pacific/Marquesas',
            'Pacific/Nauru',
            'Pacific/Niue',
            'Pacific/Norfolk',
            'Pacific/Noumea',
            'Pacific/Pago_Pago',
            'Pacific/Palau',
            'Pacific/Pitcairn',
            'Pacific/Pohnpei',
            'Pacific/Port_Moresby',
            'Pacific/Rarotonga',
            'Pacific/Tahiti',
            'Pacific/Tarawa',
            'Pacific/Tongatapu',
            'Pacific/Wake',
            'Pacific/Wallis'
        ];

        return in_array($timeZoneId, $validCanonicalTimeZones);
    }

    private function isValidIsoTime(string $time): bool
    {
        // Regex for HH:mm where HH = 00–23 and mm = 00–59
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    private function sanitizePhoneNumber($phone): string
    {
        // Remove all non-numeric characters except + and spaces and common separators
        $phone = preg_replace('/[^\d\+\-\(\)\s]/', '', $phone);
        return trim($phone);
    }

    private function sanitizeEmail($email): string
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    private function hasRequiredParams(array $data, array $requiredParams): ?array
    {
        $missingParams = [];

        foreach ($requiredParams as $param) {
            if (empty($data[$param])) {
                $missingParams[] = $param;
            }
        }

        if (!empty($missingParams)) {
            return [
                'success' => false,
                'message' => 'Missing required parameter' . (count($missingParams) > 1 ? 's' : '') . ': ' . implode(', ', $missingParams) . '.'
            ];
        }

        return ['success' => true];
    }
}
