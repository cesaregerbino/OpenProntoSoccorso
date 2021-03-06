<?php
   #***************************************************************************************************
   #*** Open Pronto Soccorsi - API to access data for Emergency Rooms in Italy
   #*** Description: get the italian Emergency Rooms data from open source sources or web pages (using scraping ...)
   #***
   #***        Note: NOT all Italian Emergency Rooms are founded, but the only ones for which
   #***              the waiting list numbers for each code (white, green, yellow and red ones) are available
   #***              in open data (services) or in some HTML web portal pages
   #***      Author: Cesare Gerbino
   #***        Code: https://github.com/cesaregerbino/OpenProntoSoccorsi
   #***     License: MIT (https://opensource.org/licenses/MIT)
   #***************************************************************************************************

   include "../Utility/SkyScannerJsonPath/vendor/autoload.php";

   include("../TelegramBot/Telegram.php");

   $errorManagerTelegramBot = ERROR_MANAGER_TELEGRAM_BOT;
   $chatIdForErrors = CHAT_ID_FOR_TO_SEND_ERROR_MESSAGES;

   date_default_timezone_set('Europe/Rome');

   # To manage special cases ...
   $special_case = '';

   # Get the Municipality name ...
   $municipality = $_GET['municipality'];

   //$municipality = "Varese";

   //echo "Municipality = ".$_GET['municipality'];
   //echo "\n";
   //echo "\n";

   # Get the distance ...
   $distance = $_GET['distance'];

   //$distance = 0;
   //echo "Distance = ".$_GET['distance'];
   //echo "\n";
   //echo "\n";

   # Substituite single quote in municipality name with two single quote ,otherwise there will be an error in the db query selection ...
   $municipality  = str_replace("'","''",$municipality);

   # Set access to data base...
   $db = new SQLite3('../Data/OpenProntoSoccorsi.sqlite');

   //echo ".... DB connection OK ...";

   # Set the query for the current Municipality for First Aid inside the municipality or in a 10 Km buffer distance...
   //$q="SELECT * FROM dist_com_ps_1 WHERE pg_COMUNE = '".$municipality."'";
   $q="SELECT * FROM dist_com_ps_1 WHERE pg_COMUNE = '".$municipality."' and dist <= ".$distance;

   //echo "Query = ".$q;
   //echo "\n";
   //echo "\n";

   try {
        # Initialize the Json ...
        $jsonResult = "{\"Comune\": \"".$municipality."\", \"prontoSoccorsi\": [";

        # Prepare for the query ...
        $stmt = $db->prepare($q);

        # Execute the query ...
        $results = $stmt->execute();


        $num_records = 0;
        while($row = $results->fetchArray(SQLITE3_ASSOC)){
          $num_records = $num_records + 1;
        }

        # Check for no data in result set ...
        if ($num_records == 0) {
          # Set the query for the current Municipality for the first five First Aids ...
          $q="SELECT * FROM dist_com_ps_1 WHERE pg_COMUNE = '".$municipality."' LIMIT 4";

          //echo "Query 2 = ".$q;
          //echo "\n";
          //echo "\n";

          # Prepare for the query ...
          $stmt = $db->prepare($q);

          # Execute the query ...
          $results = $stmt->execute();
        }

        $firstIteration = TRUE;
        $isNullValue = 0;

        # Iterate on the Emergency Rooms istances ...
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
          # Set the query for the current osm_id ...
          $q1="SELECT * FROM ps_details WHERE osm_id = '".$row['pt_osm_id']."'";

          //echo "Query = ".$q1;
          //echo "\n";
          //echo "\n";

          # Get the coordinates ...
          $pt_X = $row['pt_X'];
          $pt_Y = $row['pt_Y'];
          $pt_LON = $row['pt_LON'];
          $pt_LAT = $row['pt_LAT'];
          try {
               # Prepare for the query ...
               $stmt1 = $db->prepare($q1);

               # Execute the query ...
               $results1 = $stmt1->execute();

               //echo "..... query executed !";
               //echo "\n";
               //echo "\n";

               # Get the Emergency Room details ...
               while ($row = $results1->fetchArray(SQLITE3_ASSOC)) {
                 # Manage the POST request case  ...
                 if ($row['data_type'] == "POST") {
                   switch ($row['specific_function']) {
                        # The Sardinia hospitals case ...
                        case "OspedaliSardegna":
                          # Get the data via POST request for Sardinia hospital ...
                          $data = getDataViaPostOspedaliSardegna($row, $errorManagerTelegramBot, $chatIdForErrors);

                          if ($data != "Error") {
                            try {
                              # Create a new DOM Document and load into the data ...
                              $dom = new DOMDocument();
                              @$dom->loadHTML($data);

                              if (($firstIteration == FALSE) AND ($isNullValue >= 0)) {
                                $jsonResult .= ",";
                              }
                              $firstIteration = FALSE;

                              # Use an array, $waitingDetails[] to mantain the parsed data.
                              # If there are some parsing errors there will be a 'null' value in the current array element

                              # The white code details ...
                              $waitingDetails[0] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_bianco_attesa'], $special_case, "num_white_waiting");
                              checkParsingErrors($waitingDetails[0], "XPATH", "numeri codice bianco in attesa", $row['xpath_numeri_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[1] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_bianco_attesa'], $special_case, "time_white_waiting");
                              checkParsingErrors($waitingDetails[1], "XPATH", "tempi codice bianco in attesa", $row['xpath_tempi_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[2] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_bianco_visita'], $special_case, "num_white_in_visita");
                              checkParsingErrors($waitingDetails[2], "XPATH", "numeri codice bianco in visita", $row['xpath_numeri_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[3] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_bianco_visita'], $special_case, "time_white_in_visita");
                              checkParsingErrors($waitingDetails[3], "XPATH", "tempi codice bianco in visita", $row['xpath_tempi_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                              # The green code details ...
                              $waitingDetails[4] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_verde_attesa'], $special_case, "num_green_waiting");
                              checkParsingErrors($waitingDetails[4], "XPATH", "numeri codice verde in attesa", $row['xpath_numeri_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[5] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_verde_attesa'], $special_case, "time_green_waiting");
                              checkParsingErrors($waitingDetails[5], "XPATH", "tempi codice verde in attesa", $row['xpath_tempi_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[6] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_verde_visita'], $special_case, "num_green_in_visita");
                              checkParsingErrors($waitingDetails[6], "XPATH", "numeri codice verde in visita", $row['xpath_numeri_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[7] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_verde_visita'], $special_case, "time_green_in_visita");
                              checkParsingErrors($waitingDetails[7], "XPATH", "tempi codice verde in visita", $row['xpath_tempi_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                              # The yellow code details ...
                              $waitingDetails[8] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_giallo_attesa'], $special_case, "num_yellow_waiting");
                              checkParsingErrors($waitingDetails[8], "XPATH", "numeri codice giallo in attesa", $row['xpath_numeri_giallo_attesa'],  $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[9] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_giallo_attesa'], $special_case, "time_yellow_waiting");
                              checkParsingErrors($waitingDetails[9], "XPATH", "tempi codice giallo in attesa", $row['xpath_tempi_giallo_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[10] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_giallo_visita'], $special_case, "num_yellow_in_visita");
                              checkParsingErrors($waitingDetails[10], "XPATH", "numeri codice giallo in visita", $row['xpath_numeri_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[11] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_giallo_visita'], $special_case, "time_yellow_in_visita");
                              checkParsingErrors($waitingDetails[11], "XPATH", "tempi codice giallo in visita", $row['xpath_tempi_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                              # The red code details ...
                              $waitingDetails[12] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_rosso_attesa'], $special_case, "num_red_waiting");
                              checkParsingErrors($waitingDetails[12], "XPATH", "numeri codice rosso in attesa", $row['xpath_numeri_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[13] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_rosso_attesa'], $special_case, "time_red_waiting");
                              checkParsingErrors($waitingDetails[13], "XPATH", "tempi codice rosso in attesa", $row['xpath_tempi_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[14] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_rosso_visita'], $special_case, "num_red_in_visita");
                              checkParsingErrors($waitingDetails[14], "XPATH", "numeri codice rosso in visita", $row['xpath_numeri_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                              $waitingDetails[15] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_rosso_visita'], $special_case, "time_red_in_visita");
                              checkParsingErrors($waitingDetails[15], "XPATH", "tempi codice rosso in visita", $row['xpath_tempi_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                              # Check for 'null' values in the $waitingDetails[] array ...
                              $isNullValue = 0;
                              foreach ($waitingDetails as $detail) {
                                  if (is_null($detail)) {
                                    $isNullValue = -1;
                                    }
                              }

                              # If there are no 'null' values write the results in json format ...
                              if ($isNullValue == 0) {
                                 $jsonResult .= writeJsonResult($row, $pt_X, $pt_Y, $pt_LON, $pt_LAT, $waitingDetails);
                              }
                          } catch (Exception $e) {
                              # Prepare the error message ...
                              $errorText = "<b>OpenProntoSoccorsiBot</b>";
                              $errorText .= "\n";
                              $errorText .= "\n";
                              $errorText .= "Pronto Soccorso:  ".$row['ps_name'];
                              $errorText .= "\n";
                              $errorText .= "\n";
                              $errorText .= "Citta': ".$row['city'];
                              $errorText .= "\n";
                              $errorText .= "\n";
                              $errorText .= "Url: ".$row['url_data'];
                              $errorText .= "\n";
                              $errorText .= "\n";
                              $errorText .= "Tipo parsing: XPATH";
                              $errorText .= "\n";
                              $errorText .= "\n";
                              $errorText .= "Errore parsing XPATH di: ".$data;
                              $errorText .= "\n";
                              $errorText .= "\n";
                              $errorText .= "Catturata eccezione: ".$e->getMessage();
                              $errorText .= "\n";
                              $errorText .= "\n";

                              $isNullValue = -2;

                              # Send the error message to the Error Manager bot ...
                              invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);
                          }

                          break;
                        }
                   }
                 } else {
                   # Manage the GET request case  ...

                   # Set CURL parameters: pay attention to the PROXY config !!!!
                   $ch = curl_init();
                   curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
                   curl_setopt($ch, CURLOPT_HEADER, 0);
                   curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                   curl_setopt($ch, CURLOPT_URL, $row['url_data']);
                   curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
                   curl_setopt($ch, CURLOPT_PROXY, '');
                   curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                   $data = curl_exec($ch);
                   $curl_info = curl_getinfo($ch);
                   $curl_error = curl_error($ch);
                   curl_close($ch);

                   # Manage the curl request KO case ...
                   if ($data === false || $curl_info['http_code'] != 200) {
                     # Prepare the error message ...
                     $errorText = "<b>OpenProntoSoccorsiBot</b>";
                     $errorText .= "\n";
                     $errorText .= "\n";
                     $errorText .= "Pronto Soccorso:  ".$row['ps_name'];
                     $errorText .= "\n";
                     $errorText .= "\n";
                     $errorText .= "Citta': ".$row['city'];
                     $errorText .= "\n";
                     $errorText .= "\n";
                     $errorText .= "Url : ".$row['url_data'];
                     $errorText .= "\n";
                     $errorText .= "\n";
                     $errorText .= "HTTP code error: ".$curl_info['http_code'];
                     $errorText .= "\n";
                     $errorText .= "\n";
                     $errorText .= "Errore: ".$curl_error;;
                     $errorText .= "\n";
                     $errorText .= "\n";

                     # Send the error message to the Error Manager bot ...
                     invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);
                   }
                   else {
                     # Manage the special cases ...
                     switch ($row['specific_function']) {
                          case "Molinette":
                            $data = parsingMolinetteJSON($data); # Transform the data ...
                            break;
                          case "InfantileReginaMargherita":
                            $data = parsingMolinetteJSON($data); # Transform the data ...
                            break;
                          case "OspedaliASL1Liguria": # Need to postprocess the json output ...
                            $special_case = "OspedaliASL1Liguria";
                            break;
                          case "OspedaleMantova": # Need to postprocess the json output ...
                            $special_case = "OspedaleMantova";
                            break;
                          case "OspedalePieveCoriano": # Need to postprocess the json output ...
                            $special_case = "OspedalePieveCoriano";
                            break;
                          case "OspedaliBologna": # Need to postprocess the json output ...
                            $special_case = "OspedaliBologna";
                            break;
                          case "OspedalePrato": # Need to postprocess the json output ...
                            $data = getDataOspedalePrato($row['url_data']);
                            break;
                          case "OspedaleCaserta": # Need to manage the custuom response format ...
                            $special_case = "OspedaleCaserta";
                            break;
                     }

                     switch (true) {
                       # Manage the JSON data case ...
                       case (($row['data_type'] == "JSON") and ($data != "Error")):
                         try {
                           # Create a new JsonObject and load into the data ...
                           $jsonObject = new JsonPath\JsonObject($data);

                           # Use an array, $waitingDetails[] to mantain the parsed data.
                           # If there are some parsing errors there will be a 'null' value in the current array element

                           # The white code details ...
                           $waitingDetails[0] = getDetailsWaitingJSON($row['xpath_numeri_bianco_attesa']);
                           checkParsingErrors($waitingDetails[0], "JSON", "numeri codice bianco in attesa", $row['xpath_numeri_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[1] = getDetailsWaitingJSON($row['xpath_tempi_bianco_attesa']);
                           checkParsingErrors($waitingDetails[1], "JSON", "tempi codice bianco in attesa", $row['xpath_tempi_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[2] = getDetailsWaitingJSON($row['xpath_numeri_bianco_visita']);
                           checkParsingErrors($waitingDetails[2], "JSON", "numeri codice bianco in visita", $row['xpath_numeri_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[3] = getDetailsWaitingJSON($row['xpath_tempi_bianco_visita']);
                           checkParsingErrors($waitingDetails[3], "JSON", "tempi codice bianco in visita", $row['xpath_tempi_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # The green code details ...
                           $waitingDetails[4] = getDetailsWaitingJSON($row['xpath_numeri_verde_attesa']);
                           checkParsingErrors($waitingDetails[4], "JSON", "numeri codice verde in attesa", $row['xpath_numeri_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[5] = getDetailsWaitingJSON($row['xpath_tempi_verde_attesa']);
                           checkParsingErrors($waitingDetails[5], "JSON", "tempi codice verde in attesa", $row['xpath_tempi_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[6] = getDetailsWaitingJSON($row['xpath_numeri_verde_visita']);
                           checkParsingErrors($waitingDetails[6], "JSON", "numeri codice verde in visita", $row['xpath_numeri_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[7] = getDetailsWaitingJSON($row['xpath_tempi_verde_visita']);
                           checkParsingErrors($waitingDetails[7], "JSON", "tempi codice verde in visita", $row['xpath_tempi_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # The yellow code details ...
                           $waitingDetails[8] = getDetailsWaitingJSON($row['xpath_numeri_giallo_attesa']);
                           checkParsingErrors($waitingDetails[8], "JSON", "numeri codice giallo in attesa", $row['xpath_numeri_giallo_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[9] = getDetailsWaitingJSON($row['xpath_tempi_giallo_attesa']);
                           checkParsingErrors($waitingDetails[9], "JSON", "tempi codice giallo in attesa", $row['xpath_tempi_giallo_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[10] = getDetailsWaitingJSON($row['xpath_numeri_giallo_visita']);
                           checkParsingErrors($waitingDetails[10], "JSON", "numeri codice giallo in visita", $row['xpath_numeri_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[11] = getDetailsWaitingJSON($row['xpath_tempi_giallo_visita']);
                           checkParsingErrors($waitingDetails[11], "JSON", "tempi codice giallo in visita", $row['xpath_tempi_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # The red code details ...
                           $waitingDetails[12] = getDetailsWaitingJSON($row['xpath_numeri_rosso_attesa']);
                           checkParsingErrors($waitingDetails[12], "JSON", "numeri codice rosso in attesa", $row['xpath_numeri_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[13] = getDetailsWaitingJSON($row['xpath_tempi_rosso_attesa']);
                           checkParsingErrors($waitingDetails[13], "JSON", "tempi codice rosso in attesa", $row['xpath_tempi_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[14] = getDetailsWaitingJSON($row['xpath_numeri_rosso_visita']);
                           checkParsingErrors($waitingDetails[14], "JSON", "numeri codice rosso in visita", $row['xpath_numeri_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[15] = getDetailsWaitingJSON($row['xpath_tempi_rosso_visita']);
                           checkParsingErrors($waitingDetails[15], "JSON", "tempi codice rosso in visita", $row['xpath_tempi_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # Check for 'null' values in the $waitingDetails[] array ...
                           $isNullValue = 0;
                           foreach ($waitingDetails as $detail) {
                               if (is_null($detail)) {
                                 $isNullValue = -1;
                                 }
                           }

                          # If there are no 'null' values write the results in json format ...
                           if ($isNullValue == 0) {
                              if (($firstIteration == FALSE) AND ($isNullValue >= 0)) {
                                $jsonResult .= ",";
                              }
                              $firstIteration = FALSE;
                              $jsonResult .= writeJsonResult($row, $pt_X, $pt_Y, $pt_LON, $pt_LAT, $waitingDetails);
                           }
                         } catch (Exception $e) {
                           # Prepare the error message ...
                           $errorText = "<b>OpenProntoSoccorsiBot</b>";
                           $errorText .= "\n";
                           $errorText .= "\n";
                           $errorText .= "Pronto Soccorso:  ".$row['ps_name'];
                           $errorText .= "\n";
                           $errorText .= "\n";
                           $errorText .= "Citta': ".$row['city'];
                           $errorText .= "\n";
                           $errorText .= "\n";
                           $errorText .= "Url: ".$row['url_data'];
                           $errorText .= "\n";
                           $errorText .= "\n";
                           $errorText .= "Tipo parsing: JSON";
                           $errorText .= "\n";
                           $errorText .= "\n";
                           $errorText .= "Errore parsing JSON di: ".$data;
                           $errorText .= "\n";
                           $errorText .= "\n";
                           $errorText .= "Catturata eccezione: ".$e->getMessage();
                           $errorText .= "\n";
                           $errorText .= "\n";

                           $isNullValue = -2;

                           # Send the error message to the Error Manager bot ...
                           invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);
                         }

                         break;
                       # Manage the XPATH data case ...
                       case (($row['data_type'] == "XPATH") and ($data != "Error")):
                         try {
                           # Create a new DOM Document and load into the data ...
                           $dom = new DOMDocument();
                           @$dom->loadHTML($data);

                           if (($firstIteration == FALSE) AND ($isNullValue >= 0)) {
                             $jsonResult .= ",";
                           }
                           $firstIteration = FALSE;

                           # Use an array, $waitingDetails[] to mantain the parsed data.
                           # If there are some parsing errors there will be a 'null' value in the current array element

                           # The white code details ...
                           $waitingDetails[0] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_bianco_attesa'], $special_case, "num_white_waiting");
                           checkParsingErrors($waitingDetails[0], "XPATH", "numeri codice bianco in attesa", $row['xpath_numeri_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[1] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_bianco_attesa'], $special_case, "time_white_waiting");
                           checkParsingErrors($waitingDetails[1], "XPATH", "tempi codice bianco in attesa", $row['xpath_tempi_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[2] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_bianco_visita'], $special_case, "num_white_in_visita");
                           checkParsingErrors($waitingDetails[2], "XPATH", "numeri codice bianco in visita", $row['xpath_numeri_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[3] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_bianco_visita'], $special_case, "time_white_in_visita");
                           checkParsingErrors($waitingDetails[3], "XPATH", "tempi codice bianco in visita", $row['xpath_tempi_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # The green code details ...
                           $waitingDetails[4] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_verde_attesa'], $special_case, "num_green_waiting");
                           checkParsingErrors($waitingDetails[4], "XPATH", "numeri codice verde in attesa", $row['xpath_numeri_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[5] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_verde_attesa'], $special_case, "time_green_waiting");
                           checkParsingErrors($waitingDetails[5], "XPATH", "tempi codice verde in attesa", $row['xpath_tempi_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[6] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_verde_visita'], $special_case, "num_green_in_visita");
                           checkParsingErrors($waitingDetails[6], "XPATH", "numeri codice verde in visita", $row['xpath_numeri_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[7] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_verde_visita'], $special_case, "time_green_in_visita");
                           checkParsingErrors($waitingDetails[7], "XPATH", "tempi codice verde in visita", $row['xpath_tempi_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # The yellow code details ...
                           $waitingDetails[8] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_giallo_attesa'], $special_case, "num_yellow_waiting");
                           checkParsingErrors($waitingDetails[8], "XPATH", "numeri codice giallo in attesa", $row['xpath_numeri_giallo_attesa'],  $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[9] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_giallo_attesa'], $special_case, "time_yellow_waiting");
                           checkParsingErrors($waitingDetails[9], "XPATH", "tempi codice giallo in attesa", $row['xpath_tempi_giallo_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[10] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_giallo_visita'], $special_case, "num_yellow_in_visita");
                           checkParsingErrors($waitingDetails[10], "XPATH", "numeri codice giallo in visita", $row['xpath_numeri_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[11] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_giallo_visita'], $special_case, "time_yellow_in_visita");
                           checkParsingErrors($waitingDetails[11], "XPATH", "tempi codice giallo in visita", $row['xpath_tempi_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # The red code details ...
                           $waitingDetails[12] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_rosso_attesa'], $special_case, "num_red_waiting");
                           checkParsingErrors($waitingDetails[12], "XPATH", "numeri codice rosso in attesa", $row['xpath_numeri_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[13] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_rosso_attesa'], $special_case, "time_red_waiting");
                           checkParsingErrors($waitingDetails[13], "XPATH", "tempi codice rosso in attesa", $row['xpath_tempi_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[14] = getDetailsWaitingXPATH($dom, $row['xpath_numeri_rosso_visita'], $special_case, "num_red_in_visita");
                           checkParsingErrors($waitingDetails[14], "XPATH", "numeri codice rosso in visita", $row['xpath_numeri_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                           $waitingDetails[15] = getDetailsWaitingXPATH($dom, $row['xpath_tempi_rosso_visita'], $special_case, "time_red_in_visita");
                           checkParsingErrors($waitingDetails[15], "XPATH", "tempi codice rosso in visita", $row['xpath_tempi_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                           # Check for 'null' values in the $waitingDetails[] array ...
                           $isNullValue = 0;
                           foreach ($waitingDetails as $detail) {
                               if (is_null($detail)) {
                                 $isNullValue = -1;
                                 }
                           }

                           # If there are no 'null' values write the results in json format ...
                           if ($isNullValue == 0) {
                              $jsonResult .= writeJsonResult($row, $pt_X, $pt_Y, $pt_LON, $pt_LAT, $waitingDetails);
                           }

                         } catch (Exception $e) {
                             # Prepare the error message ...
                             $errorText = "<b>OpenProntoSoccorsiBot</b>";
                             $errorText .= "\n";
                             $errorText .= "\n";
                             $errorText .= "Pronto Soccorso:  ".$row['ps_name'];
                             $errorText .= "\n";
                             $errorText .= "\n";
                             $errorText .= "Citta': ".$row['city'];
                             $errorText .= "\n";
                             $errorText .= "\n";
                             $errorText .= "Url: ".$row['url_data'];
                             $errorText .= "\n";
                             $errorText .= "\n";
                             $errorText .= "Tipo parsing: XPATH";
                             $errorText .= "\n";
                             $errorText .= "\n";
                             $errorText .= "Errore parsing XPATH di: ".$data;
                             $errorText .= "\n";
                             $errorText .= "\n";
                             $errorText .= "Catturata eccezione: ".$e->getMessage();
                             $errorText .= "\n";
                             $errorText .= "\n";

                             $isNullValue = -2;

                             # Send the error message to the Error Manager bot ...
                             invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);
                         }
                         break;
                       # Manage the XML data case ...
                       case (($row['data_type'] == "XML") and ($data != "Error")):
                         # Use an array, $waitingDetails[] to mantain the parsed data.
                         # If there are some parsing errors there will be a 'null' value in the current array element

                         if (($firstIteration == FALSE) AND ($isNullValue >= 0)) {
                           $jsonResult .= ",";
                         }
                         $firstIteration = FALSE;


                         # The white code details ...
                         $waitingDetails[0] = getDetailsWaitingXML($data, $row['xpath_numeri_bianco_attesa']);
                         checkParsingErrors($waitingDetails[0], "XML", "numeri codice bianco in attesa", $row['xpath_numeri_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[1] = getDetailsWaitingXML($data, $row['xpath_tempi_bianco_attesa']);
                         checkParsingErrors($waitingDetails[1], "XML", "tempi codice bianco in attesa", $row['xpath_tempi_bianco_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[2] = getDetailsWaitingXML($data, $row['xpath_numeri_bianco_visita']);
                         checkParsingErrors($waitingDetails[2], "XML", "numeri codice bianco in visita", $row['xpath_numeri_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[3] = getDetailsWaitingXML($data, $row['xpath_tempi_bianco_visita']);
                         checkParsingErrors($waitingDetails[3], "XML", "tempi codice bianco in visita", $row['xpath_tempi_bianco_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                         # The green code details ...
                         $waitingDetails[4] = getDetailsWaitingXML($data, $row['xpath_numeri_verde_attesa']);
                         checkParsingErrors($waitingDetails[4], "XML", "numeri codice verde in attesa", $row['xpath_numeri_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[5] = getDetailsWaitingXML($data, $row['xpath_tempi_verde_attesa']);
                         checkParsingErrors($waitingDetails[5], "XML", "tempi codice verde in attesa", $row['xpath_tempi_verde_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[6] = getDetailsWaitingXML($data, $row['xpath_numeri_verde_visita']);
                         checkParsingErrors($waitingDetails[6], "XML", "numeri codice verde in visita", $row['xpath_numeri_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[7] = getDetailsWaitingXML($data, $row['xpath_tempi_verde_visita']);
                         checkParsingErrors($waitingDetails[7], "XML", "tempi codice verde in visita", $row['xpath_tempi_verde_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                         # The yellow code details ...
                         $waitingDetails[8] = getDetailsWaitingXML($data, $row['xpath_numeri_giallo_attesa']);
                         checkParsingErrors($waitingDetails[8], "XML", "numeri codice giallo in attesa", $row['xpath_numeri_giallo_attesa'],  $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[9] = getDetailsWaitingXML($data, $row['xpath_tempi_giallo_attesa']);
                         checkParsingErrors($waitingDetails[9], "XML", "tempi codice giallo in attesa", $row['xpath_tempi_giallo_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[10] = getDetailsWaitingXML($data, $row['xpath_numeri_giallo_visita']);
                         checkParsingErrors($waitingDetails[10], "XML", "numeri codice giallo in visita", $row['xpath_numeri_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[11] = getDetailsWaitingXML($data, $row['xpath_tempi_giallo_visita']);
                         checkParsingErrors($waitingDetails[11], "XML", "tempi codice giallo in visita", $row['xpath_tempi_giallo_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                         # The red code details ...
                         $waitingDetails[12] = getDetailsWaitingXML($data, $row['xpath_numeri_rosso_attesa']);
                         checkParsingErrors($waitingDetails[12], "XML", "numeri codice rosso in attesa", $row['xpath_numeri_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[13] = getDetailsWaitingXML($data, $row['xpath_tempi_rosso_attesa']);
                         checkParsingErrors($waitingDetails[13], "XML", "tempi codice rosso in attesa", $row['xpath_tempi_rosso_attesa'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[14] = getDetailsWaitingXML($data, $row['xpath_numeri_rosso_visita']);
                         checkParsingErrors($waitingDetails[14], "XML", "numeri codice rosso in visita", $row['xpath_numeri_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);
                         $waitingDetails[15] = getDetailsWaitingXML($data, $row['xpath_tempi_rosso_visita']);
                         checkParsingErrors($waitingDetails[15], "XML", "tempi codice rosso in visita", $row['xpath_tempi_rosso_visita'], $row, $errorManagerTelegramBot, $chatIdForErrors);

                         # Check for 'null' values in the $waitingDetails[] array ...
                         $isNullValue = 0;
                         foreach ($waitingDetails as $detail) {
                             if (is_null($detail)) {
                               $isNullValue = -1;
                               }
                         }

                         # If there are no 'null' values write the results in json format ...
                         if ($isNullValue == 0) {
                            $jsonResult .= writeJsonResult($row, $pt_X, $pt_Y, $pt_LON, $pt_LAT, $waitingDetails);
                         }
                         break;

                       //case "CUSTOM":
                       case (($row['data_type'] == "CUSTOM") and ($data != "Error")):
                           switch ($special_case) {
                             case "OspedaleCaserta":
                               $jsonResult .= getDataOspedaleCaserta($data, $row, $pt_X, $pt_Y, $pt_LON, $pt_LAT);

                               break;
                              }
                           break;
                     }
                   }
                 }
              }
          }
          catch(PDOException $e) {
                  print "Something went wrong or Connection to database failed! ".$e->getMessage();
          }
        }
        $jsonResult .= "]";
        $jsonResult .= "}";

        switch ($special_case) {
             case "OspedaliASL1Liguria": # Postprocess the jsonResult to eliminate strings ...
               $jsonResult = str_replace('Codice bianco:', '', $jsonResult);
               $jsonResult = str_replace('Codice verde:', '', $jsonResult);
               $jsonResult = str_replace('Codice giallo:', '', $jsonResult);
               $jsonResult = str_replace('Codice rosso:', '', $jsonResult);
               break;
            case "OspedaleMantova": # Postprocess the jsonResult to eliminate strings ...
               $jsonResult = str_replace('attesa": "[', 'attesa": "', $jsonResult);
               $jsonResult = str_replace('visita": "[', 'visita": "', $jsonResult);
               $jsonResult = str_replace(']",', '",', $jsonResult);
               break;
            case "OspedalePieveCoriano": # Postprocess the jsonResult to eliminate strings ...
               $jsonResult = str_replace('attesa": "[', 'attesa": "', $jsonResult);
               $jsonResult = str_replace('visita": "[', 'visita": "', $jsonResult);
               $jsonResult = str_replace(']",', '",', $jsonResult);
               break;
        }

        echo $jsonResult;
   }
   catch(PDOException $e) {
           print "Something went wrong or Connection to database failed! ".$e->getMessage();
   }
   $db = null;

   # Get the details in the case o  XPATH (data inside HTML pages) ..
   function getDetailsWaitingXPATH($dom, $xpath_for_parsing, $special_case, $type) {
     if ($xpath_for_parsing == "N.D.") {
        return "N.D.";
       }
     else {
       $xpath = new DOMXPath($dom);
       $colorWaitingNumber = $xpath->query($xpath_for_parsing);
       $theValue = null;

       foreach( $colorWaitingNumber as $node )
       {
         $theValue = $node->nodeValue;
       }

       # Post elaboration for the special cases ...
       switch ($special_case) {
           case "OspedaliBologna": # Postprocess the jsonResult to eliminate strings ...
              if (($type == "num_white_waiting") or ($type == "num_green_waiting") or ($type == "num_yellow_waiting") or ($type == "num_red_waiting")) {
                $theValue = substr($theValue, strpos($theValue, 'Utenti in coda: ') + 16);
                if ($theValue == "") $theValue = "N.D.";
              }
              if (($type == "time_white_waiting") or ($type == "time_green_waiting") or ($type == "time_yellow_waiting") or ($type == "time_red_waiting")) {
                $theValue = strstr($theValue, ' - Utenti in coda', true);
                if ($theValue == "") $theValue = "N.D.";
              }
              break;
       }
       return  $theValue;
     }
   }

   # Get the details in the case of JSON (data form open services) ...
   function getDetailsWaitingJSON($xpath_for_parsing) {
     global $jsonObject;

     #echo "XPATH for parsing = " .$xpath_for_parsing;
     #echo "\n";
     #echo "\n";

     if ($xpath_for_parsing == "N.D.") {
        return "N.D.";
       }
     else {
        $match1 = $jsonObject->get($xpath_for_parsing);
        return  $match1[0];
     }
   }

   # Get the details in the case of XML ...
   function getDetailsWaitingXML($data, $xpath_for_parsing) {
     if ($xpath_for_parsing == "N.D.") {
        return "N.D.";
       }
     else {
       $xml = new SimpleXMLElement($data);

       $result = $xml->xpath($xpath_for_parsing);

       $theValue = null;

       while(list( , $node) = each($result)) {
         $theValue = $node;
       }

       return $theValue;
    }
   }

   # Manage the Molinette special case ...
   function parsingMolinetteJSON($data) {
     $json = json_decode($data, true);
     $json_new = "{\"colors\": [";
     $get_value = 0;
     $json_new .= "{";
     $json_new .= "\"colore\": \"bianco\",";
     foreach ($json['colors'] as $color) {
       if ($color['colore'] == "bianco") {
         $json_new .= "\"attesa\": \"".$color['attesa']."\",";
         $json_new .= "\"visita\": \"".$color['visita']."\"";
         $get_value = 1;
       }
     }
     if ($get_value == 0) {
       $json_new .= "\"attesa\": \"0\"".",";
       $json_new .= "\"visita\": \"0\"";
     }
     $json_new .= "},";
     $get_value = 0;
     $json_new .= "{";
     $json_new .= "\"colore\": \"verde\",";
     foreach ($json['colors'] as $color) {
       if ($color['colore'] == "verde") {
         $json_new .= "\"attesa\": \"".$color['attesa']."\",";
         $json_new .= "\"visita\": \"".$color['visita']."\"";
         $get_value = 1;
       }
     }
     if ($get_value == 0) {
       $json_new .= "\"attesa\": \"0\"".",";
       $json_new .= "\"visita\": \"0\"";
     }
     $json_new .= "},";
     $get_value = 0;
     $json_new .= "{";
     $json_new .= "\"colore\": \"giallo\",";
     foreach ($json['colors'] as $color) {
       if ($color['colore'] == "giallo") {
         $json_new .= "\"attesa\": \"".$color['attesa']."\",";
         $json_new .= "\"visita\": \"".$color['visita']."\"";
         $get_value = 1;
       }
     }
     if ($get_value == 0) {
       $json_new .= "\"attesa\": \"0\"".",";
       $json_new .= "\"visita\": \"0\"";
     }
     $json_new .= "},";
     $get_value = 0;
     $json_new .= "{";
     $json_new .= "\"colore\": \"rosso\",";
     foreach ($json['colors'] as $color) {
       if ($color['colore'] == "rosso") {
         $json_new .= "\"attesa\": \"".$color['attesa']."\",";
         $json_new .= "\"visita\": \"".$color['visita']."\"";
         $get_value = 1;
       }
     }
     if ($get_value == 0) {
       $json_new .= "\"attesa\": \"0\"".",";
       $json_new .= "\"visita\": \"0\"";
     }
     $json_new .= "}";
     $json_new .= "]}";
     return $json_new;
   }

   # Get the data for Sardinia Hospitals via POST request  ...
   function getDataViaPostOspedaliSardegna($row, $errorManagerTelegramBot, $chatIdForErrors) {
     $ch = curl_init();

     curl_setopt_array($ch, array(
       CURLOPT_URL => $row['url_data'],
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_ENCODING => "",
       CURLOPT_MAXREDIRS => 10,
       CURLOPT_TIMEOUT => 10,
       CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
       CURLOPT_CUSTOMREQUEST => "POST",
       CURLOPT_POSTFIELDS => "idMacroArea=null&codiceAziendaSanitaria=null&idAreaVasta=null&idPresidio=null&tipoProntoSoccorso=null&vicini=null&xhr=null",
       CURLOPT_HTTPHEADER => array(
         "cache-control: no-cache",
         "content-type: application/x-www-form-urlencoded"
       ),
     ));
     $server_output = curl_exec ($ch);
     $curl_info = curl_getinfo($ch);
     $curl_error = curl_error($ch);
     curl_close ($ch);

     # Manage the curl request KO case ...
     if ($server_output === false || $curl_info['http_code'] != 200) {
       # Prepare the error message ...
       $errorText = "<b>OpenProntoSoccorsiBot</b>";
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Errore in chiamata in POST (Ospedali Sardegna): ".$row['url_data'];
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "HTTP code error: ".$curl_info['http_code'];
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Errore: ".$curl_error;
       $errorText .= "\n";
       $errorText .= "\n";

       # Send the error message to the Error Manager bot ...
       invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);

       return "Error";
     }
     else {
       try {
         # Create a new JsonObject and load into the data ...
         $jsonObject = new JsonPath\JsonObject($server_output);

         $jsonPathExpr = '$..view';

         $res = $jsonObject->get($jsonPathExpr);

         return $res[0];
       } catch (Exception $e) {
         # Prepare the error message ...
         $errorText = "<b>OpenProntoSoccorsiBot</b>";
         $errorText .= "\n";
         $errorText .= "\n";
         $errorText .= "Pronto Soccorso:  ".$row['ps_name'];
         $errorText .= "\n";
         $errorText .= "\n";
         $errorText .= "Citta': ".$row['city'];
         $errorText .= "\n";
         $errorText .= "\n";
         $errorText .= "Url: ".$row['url_data'];
         $errorText .= "\n";
         $errorText .= "\n";
         $errorText .= "Tipo parsing: JSON";
         $errorText .= "\n";
         $errorText .= "\n";
         $errorText .= "Errore parsing JSON di: ".$server_output;
         $errorText .= "\n";
         $errorText .= "\n";
         $errorText .= "Catturata eccezione: ".$e->getMessage();
         $errorText .= "\n";
         $errorText .= "\n";

         # Send the error message to the Error Manager bot ...
         invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);
       }
    }
   }

   # Get the data for Prato Hospital ...
   function getDataOspedalePrato($url) {
     global $jsonResult;

     # Set CURL parameters: pay attention to the PROXY config !!!!
     $ch = curl_init();
     curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
     curl_setopt($ch, CURLOPT_HEADER, 0);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
     curl_setopt($ch, CURLOPT_URL, $url);
     curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
     curl_setopt($ch, CURLOPT_PROXY, '');
     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

     # In this case is needed manage cookies ..
     $cookies = "./cookie.txt";
     curl_setopt($ch, CURLOPT_COOKIEJAR, $cookies);
     curl_setopt($ch, CURLOPT_COOKIEFILE, $cookies);

     # In this case is needed to get a double request to obtain the real data ...
     $data = curl_exec($ch);
     $data = curl_exec($ch);

     $curl_info = curl_getinfo($ch);
     $curl_error = curl_error($ch);

     curl_close($ch);

     # Manage the curl request KO case ...
     if ($data === false || $curl_info['http_code'] != 200) {
       # Prepare the error message ...
       $errorText = "<b>OpenProntoSoccorsiBot</b>";
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Errore in chiamata in GET (Ospedale Prato): ".$row['url_data'];
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "HTTP code error: ".$curl_info['http_code'];
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Errore: ".$curl_error;;
       $errorText .= "\n";
       $errorText .= "\n";

       # Send the error message to the Error Manager bot ...
       invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);
       return "Error";
     }
    else {
      return $data;
     }
   }

   function invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $theErrorMessage) {
     $website="https://api.telegram.org/bot".$errorManagerTelegramBot;
     $params=[
         'chat_id'=>$chatIdForErrors,
         'text'=>$theErrorMessage,
         'parse_mode'=> "HTML"
     ];
     $ch = curl_init($website . '/sendMessage');
     curl_setopt($ch, CURLOPT_HEADER, false);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
     curl_setopt($ch, CURLOPT_POST, 1);
     curl_setopt($ch, CURLOPT_POSTFIELDS, ($params));
     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
     $result = curl_exec($ch);
     curl_close($ch);
   }


   function writeJsonResult($row, $pt_X, $pt_Y, $pt_LON, $pt_LAT, $waitingDetails) {

     $jsonResult  = "{";
     $jsonResult .= "\"osm_id\": \"".$row['osm_id']."\",";
     $jsonResult .= "\"x\": \"".$pt_X."\",";
     $jsonResult .= "\"y\": \"".$pt_Y."\",";
     $jsonResult .= "\"Lon\": \"".$pt_LON."\",";
     $jsonResult .= "\"Lat\": \"".$pt_LAT."\",";
     $jsonResult .= "\"ps_name\": \"".$row['ps_name']."\",";
     $jsonResult .= "\"city\": \"".$row['city']."\",";
     $jsonResult .= "\"address\": \"".$row['address']."\",";
     $jsonResult .= "\"tel\": \"".$row['tel']."\",";
     $jsonResult .= "\"email\": \"".$row['email']."\",";
     $jsonResult .= "\"url_website\": \"".$row['url_website']."\",";

     # The white code details ...
     $jsonResult .= "\"numeri_bianco_attesa\": \"".$waitingDetails[0]."\"";
     $jsonResult .= ",\"tempi_bianco_attesa\": \"".$waitingDetails[1]."\"";
     $jsonResult .= ",\"numeri_bianco_in_visita\": \"".$waitingDetails[2]."\"";
     $jsonResult .= ",\"tempi_bianco_in_visita\": \"".$waitingDetails[3]."\"";
     # The green code details ...
     $jsonResult .= ",\"numeri_verde_attesa\": \"".$waitingDetails[4]."\"";
     $jsonResult .= ",\"tempi_verde_attesa\": \"".$waitingDetails[5]."\"";
     $jsonResult .= ",\"numeri_verde_in_visita\": \"".$waitingDetails[6]."\"";
     $jsonResult .= ",\"tempi_verde_in_visita\": \"".$waitingDetails[7]."\"";
     # The yellow code details ...
     $jsonResult .= ",\"numeri_giallo_attesa\": \"".$waitingDetails[8]."\"";
     $jsonResult .= ",\"tempi_giallo_attesa\": \"".$waitingDetails[9]."\"";
     $jsonResult .= ",\"numeri_giallo_in_visita\": \"".$waitingDetails[10]."\"";
     $jsonResult .= ",\"tempi_giallo_in_visita\": \"".$waitingDetails[11]."\"";
     # The red code details ...
     $jsonResult .= ",\"numeri_rosso_attesa\": \"".$waitingDetails[12]."\"";
     $jsonResult .= ",\"tempi_rosso_attesa\": \"".$waitingDetails[13]."\"";
     $jsonResult .= ",\"numeri_rosso_in_visita\": \"".$waitingDetails[14]."\"";
     $jsonResult .= ",\"tempi_rosso_in_visita\": \"".$waitingDetails[15]."\"";

     $jsonResult .= "}";

     //echo $jsonResult;

     return $jsonResult;
   }


   function checkParsingErrors($waitingDetails, $parsingType, $dataSearched, $parsingString, $row, $errorManagerTelegramBot, $chatIdForErrors) {
     if (is_null($waitingDetails)) {
       $errorText = "<b>OpenProntoSoccorsiBot</b>";
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Errore per recupero ".$dataSearched;
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Pronto Soccorso:  ".$row['ps_name'];
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Citta': ".$row['city'];
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Url: ".$row['url_data'];
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Tipo parsing: ".$parsingType;
       $errorText .= "\n";
       $errorText .= "\n";
       $errorText .= "Parsing: ".$parsingString;
       $errorText .= "\n";
       $errorText .= "\n";
       invokeErrorManagerBot($errorManagerTelegramBot, $chatIdForErrors, $errorText);
     }
   }

   function getDataOspedaleCaserta($data, $row, $pt_X, $pt_Y, $pt_LON, $pt_LAT) {
     $pieces = explode (",", $data);

     $jsonResult .= "{";
     $jsonResult .= "\"osm_id\": \"".$row['osm_id']."\",";
     $jsonResult .= "\"x\": \"".$pt_X."\",";
     $jsonResult .= "\"y\": \"".$pt_Y."\",";
     $jsonResult .= "\"Lon\": \"".$pt_LON."\",";
     $jsonResult .= "\"Lat\": \"".$pt_LAT."\",";
     $jsonResult .= "\"ps_name\": \"".$row['ps_name']."\",";
     $jsonResult .= "\"city\": \"".$row['city']."\",";
     $jsonResult .= "\"address\": \"".$row['address']."\",";
     $jsonResult .= "\"tel\": \"".$row['tel']."\",";
     $jsonResult .= "\"email\": \"".$row['email']."\",";
     $jsonResult .= "\"url_website\": \"".$row['url_website']."\",";

     # The white code details ...
     $num_white_waiting = $pieces[4];
     $jsonResult .= "\"numeri_bianco_attesa\": \"".trim($num_white_waiting,"\n")."\",";
     $time_white_waiting = "N.D.";
     $jsonResult .= "\"tempi_bianco_attesa\": \"".$time_white_waiting."\",";
     $num_white_in_visita = "N.D.";
     $jsonResult .= "\"numeri_bianco_in_visita\": \"".$num_white_in_visita."\",";
     $time_white_in_visita = "N.D.";
     $jsonResult .= "\"tempi_bianco_in_visita\": \"".$time_white_in_visita."\",";
     # The green code details ...
     $num_green_waiting = $pieces[3];
     $jsonResult .= "\"numeri_verde_attesa\": \"".$num_green_waiting."\",";
     $time_green_waiting = "N.D.";
     $jsonResult .= "\"tempi_verde_attesa\": \"".$time_green_waiting."\",";
     $num_green_in_visita = "N.D.";
     $jsonResult .= "\"numeri_verde_in_visita\": \"".$num_green_in_visita."\",";
     $time_green_in_visita = "N.D.";
     $jsonResult .= "\"tempi_verde_in_visita\": \"".$time_green_in_visita."\",";
     # The yellow details ...
     $num_yellow_waiting = $pieces[2];
     $jsonResult .= "\"numeri_giallo_attesa\": \"".$num_yellow_waiting."\",";
     $time_yellow_waiting = "N.D.";
     $jsonResult .= "\"tempi_giallo_attesa\": \"".$time_yellow_waiting."\",";
     $num_yellow_in_visita = "N.D.";
     $jsonResult .= "\"numeri_giallo_in_visita\": \"".$num_yellow_in_visita."\",";
     $time_yellow_in_visita = "N.D.";
     $jsonResult .= "\"tempi_giallo_in_visita\": \"".$time_yellow_in_visita."\",";
     # The red details ...
     $num_red_waiting = $pieces[1];
     $jsonResult .= "\"numeri_rosso_attesa\": \"".$num_red_waiting."\",";
     $time_red_waiting = "N.D.";
     $jsonResult .= "\"tempi_rosso_attesa\": \"".$time_red_waiting."\",";
     $num_red_in_visita = "N.D.";
     $jsonResult .= "\"numeri_rosso_in_visita\": \"".$num_red_in_visita."\",";
     $time_red_in_visita = "N.D.";
     $jsonResult .= "\"tempi_rosso_in_visita\": \"".$time_red_in_visita."\"";
     $jsonResult .= "}";

     return $jsonResult;
   }


?>
