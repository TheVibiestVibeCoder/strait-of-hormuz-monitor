    aisstream.io
    Documentation
    Github

Authentication
Browser And Cross Origin Requests
Creating a Connection
Connection Subscription Parameters
Updating A Subscription
Keeping Your Connection Alive
Websocket Messages
Examples
Javascript
Python
Additional Sample Applications
Common Questions
How do I get support for an issue I have with the service ?
API Resources
Create Subscription Message
AISMessage
Error Message
API Message Models
BaseStationReport
MultiSlotBinaryMessage
ExtendedClassBPositionReport
SafetyBroadcastMessage
PositionReport
ShipStaticData
StandardClassBPositionReport
StandardSearchAndRescueAircraftReport
StaticDataReport
SingleSlotBinaryMessage
Interrogation
LongRangeAisBroadcastMessage
GnssBroadcastBinaryMessage
DataLinkManagementMessage
AddressedSafetyMessage
AddressedBinaryMessage
CoordinatedUTCInquiry
BinaryAcknowledge
ChannelManagement
AssignedModeCommand
AidsToNavigationReport
aistream.io is in BETA!

aisstream.io is still a new service and currently is in BETA. We make no guarantees and provide no SLA for the uptime of our service!
AIS Stream API Reference

aistream.io is websocket api that allows a user to stream maritime data such as a ship's positions, direction of travel, and much more. All service interactions take place via a single websocket connection including the subscription and authentication of clients. A select number of languages have message type definitions available Here in additional to openapi Definitions.
Authentication

AIS-Stream websockets uses API keys for authentication. Only users who have Signed in, via GitHub or other supported methods, can generate and revoke api keys. API keys can be created for authenticated users via the API Keys page. When an API key is revoked, either by yourself or in a rare case our team, it will still appear in the console but will be marked invalid.
wss only!

All actions involving API keys must be made using https or via wss (websockets version of HTTPS) to ensure security.
Browser And Cross Origin Requests

Cross-origin resource sharing and thus connections directly to aistream.io from the browser are not supported by aisstream.io.

The reason for this can be summarized as follows:

1. API Keys were not designed to be shared on the open internet. If a user creates a site which connects directly to aisstream.io, their api key is now available on the internet to all consumers of their site to use. Our desired design pattern is if a user wants to use aisstream.io in a web application, is thay they consume the websocket on their backend and deliver the data to their clients via connections they control. This keeps the api key away from the open internet.

2. We have throttling at the api key and user level. Your users may be throttled if your app becomes popular and have many concurrent connections with the same api key.
Creating a Connection
AIStream's url

wss://stream.aisstream.io/v0/stream

Websocket AIS streams are created by creating a websocket connection to wss://stream.aisstream.io/v0/stream and sending a json subscription message. This message includes your api key and the geographic bounding boxes you wish to receive AIS data. A bounding box (usually shortened to bbox) is an area defined by two longitudes and two latitudes, where: Latitude is a decimal number between -90.0 and 90.0. Longitude is a decimal number between -180.0 and 180.0.

Optionally you can specify parameters to only receive ais messages from a desired set of vessels specified by their MMSI or only receive a certain subset of ais messages types from aisstream.io by setting the values FiltersShipMMSI and FilterMessageTypes.

Note boxes can over overlap with no duplication of data received. An example of a subscription message is available below which filter for messages pertaining to ship's located near the ports of Miami and Los Angles. Note the subscription message must be sent within 3 seconds of creating your websocket to AISStream or your connection will be closed.
Subscription Creation Message

{
   "APIKey": <your api key>, // Required!
   "BoundingBoxes": [[[25.835302, -80.207729], [25.602700, -79.879297]], [[33.772292, -118.356139], [33.673490, -118.095731]] ], // Required!
   "FiltersShipMMSI": ["368207620", "367719770", "211476060"] // Optional!
   "FilterMessageTypes": ["PositionReport"] // Optional!
}

subscription creation message For example for the port of LA and Miami
Connection Subscription Parameters

Bounding Box

The formatting for "bounding_boxes" is as follows:

[[[lat bounding box 1st corner, long bounding box 1st corner], [lat bounding box 1 2nd corner, long bounding box 1 2nd corner]]]

Note, the order of the bounding box corners has no affect.

MMSI Filter

The formatting for FiltersShipMMSI is as follows with maximum of 50 MMSI values supported.

["SHIPMMSI1", "SHIPMMSI2", ...]

Message Type Filter

The formatting for FilterMessageTypes is as follows, with all AIS messages types support. Passing a message type twice will result in an error

["AISMessageType1", "AISMessageType2", ...]
Subscription Timeout

Upon websocket connection, if a subscription message is not received by the server in 3 seconds or less the connection will be closed
Updating A Subscription

An active subscription can be updated simply by resending a subscription message on the same websocket connection. This is a swap and replace operation and the new subscription bounding boxes will replace the previous. Note, the updated subscription will not be the merger of the two subscriptions.
Keeping Your Connection Alive

At aisstream.io we use a variety of methods to determine if your connection to our service is alive and healthy. The main method we use to determine if a connection is healthy is by monitoring the amount of data in queue waiting to be read by the underlying tcp connection you have made to our service. If this queue is above a certain size aisstream.io may close your connection to our service. To prevent this from happening using an internet connection to our service with high bandwidth and sufficient resources (cpu and memory) to process on average 300 messages a second (if subscribed to the entire world) is required.
Websocket Messages

After a successful subscription, AIS messages will begin to be received on your websocket. All messages will follow the same format which includes the message's type, the AIS message in json format and metadata (possibly useful data, that is not contained in the traditional AIS message such as last known latitude, longitude or the vessel's name).
AIS Message Format

{
  "MessageType": "<Message Type>",
  "Metadata": {
    "Latitude": -54.0,
    "Longitude": -87.0,
    ...
  }, 
  "Message": {
    "<Message Type Key>": { <AIS Message Body>....}
  }
}

A real message example is given in the object definition documentation below.
OpenAPI 3.0 AIS Message format

The traditional AIS protocol has a large variety of message types giving information such as current ship position, direction, and ship name in a complex format that may involve many packets for one message. To alleviate this complexity all AIS message types have been converted into json format and defined via open-api models open-api models as well as objects for select languages. This allows you to work with AIS messages using standard tools and libraries.
Examples

AISStream's example repository is a work in progress, if you have a sample you wish to share please reach out. Additional sample apps can be found on Github.
Javascript
Javascript Example

const WebSocket = require('ws');
const socket = new WebSocket("wss://stream.aisstream.io/v0/stream")

socket.onopen = function (_) {
    let subscriptionMessage = {
        Apikey: "<YOUR API KEY>",
        BoundingBoxes: [[[-90, -180], [90, 180]]],
        FiltersShipMMSI: ["368207620", "367719770", "211476060"], // Optional!
        FilterMessageTypes: ["PositionReport"] // Optional!
    }
    socket.send(JSON.stringify(subscriptionMessage));
};

socket.onmessage = function (event) {
    let aisMessage = JSON.parse(event.data)
    console.log(aisMessage)
};

Python

The following example prints to the screen the position updates of every ship in the world that our AIS stations receive data from.
Python Example

import asyncio
import websockets
import json
from datetime import datetime, timezone

async def connect_ais_stream():

    async with websockets.connect("wss://stream.aisstream.io/v0/stream") as websocket:
        subscribe_message = {"APIKey": "<API KEY>",  # Required !
                             "BoundingBoxes": [[[-90, -180], [90, 180]]], # Required!
                             "FiltersShipMMSI": ["368207620", "367719770", "211476060"], # Optional!
                             "FilterMessageTypes": ["PositionReport"]} # Optional!

        subscribe_message_json = json.dumps(subscribe_message)
        await websocket.send(subscribe_message_json)

        async for message_json in websocket:
            message = json.loads(message_json)
            message_type = message["MessageType"]

            if message_type == "PositionReport":
                # the message parameter contains a key of the message type which contains the message itself
                ais_message = message['Message']['PositionReport']
                print(f"[{datetime.now(timezone.utc)}] ShipId: {ais_message['UserID']} Latitude: {ais_message['Latitude']} Latitude: {ais_message['Longitude']}")

if __name__ == "__main__":
    asyncio.run(asyncio.run(connect_ais_stream()))

Additional Sample Applications

aisstream.io supports all languages which a websocket can be implemented in. For a select number of languages we include samples for your learning listed below.
Golang
Python
Javascript
Java
Common Questions
How do I get support for an issue I have with the service ?

Please create an issue on github in the following repo https://github.com/aisstream/issues. We will get to it as soon as we can.
API Resources
Unstable API!

As aisstream.io is in beta, our api and object models are currently NOT stable. Please check regulary for updates.
Create Subscription Message

The object used upon connection to create your subscription to ais events. It includes your api key and the bounding box of the area you which to subscribe to message for.

FiltersShipMMSI is a list of MMSI in string format. A maximum of 50 MMSI values is supported.

FilterMessageTypes is a list of message type names in string format from the list of AIS message type names below.

PositionReport, UnknownMessage, AddressedSafetyMessage, AddressedBinaryMessage, AidsToNavigationReport, AssignedModeCommand, BaseStationReport BinaryAcknowledge, BinaryBroadcastMessage, ChannelManagement, CoordinatedUTCInquiry, DataLinkManagementMessage, DataLinkManagementMessageData, ExtendedClassBPositionReport GroupAssignmentCommand, GnssBroadcastBinaryMessage, Interrogation, LongRangeAisBroadcastMessage, MultiSlotBinaryMessage, SafetyBroadcastMessage, ShipStaticData SingleSlotBinaryMessage, StandardClassBPositionReport, StandardSearchAndRescueAircraftReport, StaticDataReport.
Attributes
APIKey String
BoundingBoxes List
FiltersShipMMSI List
FilterMessageTypes List
CreateSubscriptionMessage

{
   "APIKey": <your api key>,
   # list of arrays containing the lat and long of the two corners of the bounding box
   "BoundingBoxes": [[[-90, -180], [90, 180]]],
   "FiltersShipMMSI": ["368207620", "367719770", "211476060"], # Optional field.
   "FilterMessageTypes": ["PositionReport"] # Optional Field. 
}

AISMessage

The message type passed for each AIS event. It contains metadata about the message such as the position and ship name, the message type and the message field. The message field contains a json object with the AIS message contained inside with the key being the AIS message type or in other words the value of the MessageType key.
Attributes
MetaData Object
MessageType AisMessageTypes
Message AisStreamMessage_Message
AisStreamMessage

{
   "Message":{
      "PositionReport":{
         "Cog":308,
         "CommunicationState":81982,
         "Latitude":66.02695,
         "Longitude":12.253821666666665,
         "MessageID":1,
         "NavigationalStatus":15,
         "PositionAccuracy":true,
         "Raim":false,
         "RateOfTurn":4,
         "RepeatIndicator":0,
         "Sog":0,
         "Spare":0,
         "SpecialManoeuvreIndicator":0,
         "Timestamp":31,
         "TrueHeading":235,
         "UserID":259000420,
         "Valid":true
      }
   },
   "MessageType":"PositionReport",
   "MetaData":{
      "MMSI":259000420,
      "ShipName":"AUGUSTSON",
      "latitude":66.02695,
      "longitude":12.253821666666665,
      "time_utc":"2022-12-29 18:22:32.318353 +0000 UTC"
   }
}

Error Message

Message returned in the event of an error interacting with the service. It could be due to throttling (you can send a maximum of 1 subscription update a second), or any other error such as an invalid api key.
Attributes
error String
ErrorMessage

{ "error": "Api Key Is Not Valid" }

API Message Models
BaseStationReport

The BaseStationReport AIS message is specifically designed to provide information about the location and status of a fixed AIS base station, such as a coastal station or a vessel traffic service (VTS) center.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
UtcYear Integer
UtcMonth Integer
UtcDay Integer
UtcHour Integer
UtcMinute Integer
UtcSecond Integer
PositionAccuracy Boolean
Longitude Double
Latitude Double
FixType Integer
LongRangeEnable Boolean
Spare Integer
Raim Boolean
CommunicationState Integer
BaseStationReport

{
  "CommunicationState": 20180,
  "FixType": 15,
  "Latitude": 43.49155666666666,
  "LongRangeEnable": false,
  "Longitude": -5.941905,
  "MessageID": 4,
  "PositionAccuracy": false,
  "Raim": true,
  "RepeatIndicator": 0,
  "Spare": 0,
  "UserID": 2241118,
  "UtcDay": 9,
  "UtcHour": 7,
  "UtcMinute": 53,
  "UtcMonth": 9,
  "UtcSecond": 30,
  "UtcYear": 2022,
  "Valid": true
}

MultiSlotBinaryMessage

The MultiSlotBinaryMessage message is used to transmit binary data in multiple time slots. This allows for the transmission of larger amounts of data than can be transmitted in a single message. The message is divided into a number of time slots, and each time slot can carry a certain amount of data.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
DestinationIDValid Boolean
ApplicationIDValid Boolean
DestinationID Integer
Spare1 Integer
ApplicationID AddressedBinaryMessage_ApplicationID
Payload String
Spare2 Integer
CommunicationStateIsItdma Boolean
CommunicationState Integer
MultiSlotBinaryMessage

{
  "ApplicationID": {
    "DesignatedAreaCode": 366,
    "FunctionIdentifier": 10,
    "Valid": true
  },
  "ApplicationIDValid": true,
  "CommunicationState": 36002,
  "CommunicationStateIsItdma": true,
  "DestinationID": 0,
  "DestinationIDValid": false,
  "MessageID": 26,
  "Payload": "",
  "RepeatIndicator": 0,
  "Spare1": 0,
  "Spare2": 0,
  "UserID": 367635490,
  "Valid": true
}

ExtendedClassBPositionReport

An Extended Class B Position Report is a message transmitted by a Class B AIS transceiver that provides information about the current position, course, and speed of a vessel. This message is intended to be used by other vessels and coastal authorities to improve situational awareness and collision avoidance. The Extended Class B Position Report message is similar to the Class B Position Report (AIS message type 5) but includes additional information such as the vessel's rate of turn, heading, and navigational status. It is typically transmitted every 10 seconds or less, depending on the vessel's speed.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare1 Integer
Sog Double
PositionAccuracy Boolean
Longitude Double
Latitude Double
Cog Double
TrueHeading Integer
Timestamp Integer
Spare2 Integer
Name String
Type Integer
Dimension ShipStaticData_Dimension
FixType Integer
Raim Boolean
Dte Boolean
AssignedMode Boolean
Spare3 Integer
ExtendedClassBPositionReport

{
  "AssignedMode": false,
  "Cog": 234.5,
  "Dimension": {
    "A": 0,
    "B": 0,
    "C": 0,
    "D": 0
  },
  "Dte": false,
  "FixType": 1,
  "Latitude": 22.314829999999997,
  "Longitude": 114.510795,
  "MessageID": 19,
  "Name": "51118-02-75%",
  "PositionAccuracy": false,
  "Raim": false,
  "RepeatIndicator": 0,
  "Sog": 0,
  "Spare1": 226,
  "Spare2": 0,
  "Spare3": 0,
  "Timestamp": 0,
  "TrueHeading": 511,
  "Type": 0,
  "UserID": 225111802,
  "Valid": true
}

SafetyBroadcastMessage

A SafetyBroadcastMessage message is intended to alert other vessels in the area about potential safety hazards or issues. This message may contain information about the vessel's location, heading, speed, and other pertinent details. It may also include information about any hazards or obstacles in the vessel's immediate vicinity, such as rocks, shallow water, or other navigational dangers. The SafetyBroadcastMessage is typically transmitted on a repeating loop, allowing other vessels in the area to receive and process the information in a timely manner. This message is an important tool for promoting safe navigation and avoiding collisions or other accidents on the water.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare Integer
Text String
SafetyBroadcastMessage

{
  "MessageID": 14,
  "RepeatIndicator": 3,
  "Spare": 0,
  "Text": "CRASH:413900364 POS:22^18.279N,114^9.451E",
  "UserID": 22,
  "Valid": true
}

PositionReport

An PositionReport AIS message is used to report the vessel's current position, heading, speed, and other relevant information to other vessels and coastal authorities. This message includes the vessel's unique MMSI (Maritime Mobile Service Identity) number, the latitude and longitude of its current position, the vessel's course over ground (COG) and speed over ground (SOG), the type of navigation status the vessel is in (e.g. underway using engine, anchored, etc.), and the vessel's dimensional information (length, width, and type). This information is used to help identify and track vessels in order to improve safety, efficiency, and compliance in the maritime industry.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
NavigationalStatus Integer
RateOfTurn Integer
Sog Double
PositionAccuracy Boolean
Longitude Double
Latitude Double
Cog Double
TrueHeading Integer
Timestamp Integer
SpecialManoeuvreIndicator Integer
Spare Integer
Raim Boolean
CommunicationState Integer
PositionReport

{
   "Cog":0,
   "CommunicationState":59916,
   "Latitude":51.44458833333333,
   "Longitude":3.590816666666667,
   "MessageID":1,
   "NavigationalStatus":7,
   "PositionAccuracy":true,
   "Raim":true,
   "RateOfTurn":0,
   "RepeatIndicator":0,
   "Sog":0,
   "Spare":0,
   "SpecialManoeuvreIndicator":0,
   "Timestamp":12,
   "TrueHeading":17,
   "UserID":245473000,
   "Valid":true
}

ShipStaticData

An ShipStaticData AIS message contains static data about the vessel, such as its name, call sign, length, width, and type of vessel. It also includes information about the vessel's owner or operator, as well as its place of build and its gross tonnage. This message is transmitted at regular intervals, usually every few minutes, and is used by other vessels and coastal authorities to identify and track the vessel. It is an important safety feature that helps to prevent collisions and improve navigation in crowded waterways.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
AisVersion Integer
ImoNumber Integer
CallSign String
Name String
Type Integer
Dimension ShipStaticData_Dimension
FixType Integer
Eta ShipStaticData_Eta
MaximumStaticDraught Double
Destination String
Dte Boolean
Spare Boolean
ShipStaticData

{
  "AisVersion": 2,
  "CallSign": "LBHF",
  "Destination": "COASTGUARD@@@@@@@@H",
  "Dimension": {
    "A": 20,
    "B": 27,
    "C": 7,
    "D": 7
  },
  "Dte": false,
  "Eta": {
    "Day": 0,
    "Hour": 0,
    "Minute": 0,
    "Month": 0
  },
  "FixType": 1,
  "ImoNumber": 9353333,
  "MaximumStaticDraught": 4.5,
  "MessageID": 5,
  "Name": "KV FARM",
  "RepeatIndicator": 0,
  "Spare": false,
  "Type": 55,
  "UserID": 257069200,
  "Valid": true
}

StandardClassBPositionReport

The StandardClassBPositionReport AIS message includes information such as the vessel's identification number, latitude and longitude coordinates, course and speed over ground, and navigation status. It also includes the vessel's dimensions, such as its length, width, and draft, as well as information about its cargo and the type of vessel it is (e.g. tanker, cargo ship, fishing vessel, etc.).

In addition to the vessel's position and related information, the StandardClassBPositionReport AIS message may also include information about the vessel's communication and safety equipment, such as its VHF radios, radar, and emergency positioning beacons. This information is used by other vessels and authorities to track the vessel's movements and ensure safe navigation.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare1 Integer
Sog Double
PositionAccuracy Boolean
Longitude Double
Latitude Double
Cog Double
TrueHeading Integer
Timestamp Integer
Spare2 Integer
ClassBUnit Boolean
ClassBDisplay Boolean
ClassBDsc Boolean
ClassBBand Boolean
ClassBMsg22 Boolean
AssignedMode Boolean
Raim Boolean
CommunicationStateIsItdma Boolean
CommunicationState Integer
StandardClassBPositionReport

{
  "AssignedMode": false,
  "ClassBBand": true,
  "ClassBDisplay": false,
  "ClassBDsc": true,
  "ClassBMsg22": true,
  "ClassBUnit": true,
  "Cog": 210.5,
  "CommunicationState": 393222,
  "CommunicationStateIsItdma": true,
  "Latitude": 39.562353333333334,
  "Longitude": 2.6283216666666664,
  "MessageID": 18,
  "PositionAccuracy": true,
  "Raim": true,
  "RepeatIndicator": 0,
  "Sog": 0,
  "Spare1": 0,
  "Spare2": 0,
  "Timestamp": 49,
  "TrueHeading": 511,
  "UserID": 367000980,
  "Valid": true
}

StandardSearchAndRescueAircraftReport

The StandardSearchAndRescueAircraftReport AIS message is used to report the position and status of a search and rescue aircraft. It includes information such as the aircraft's identification, course, speed, and location. It also includes details about any search and rescue activities that are being carried out, including the type of distress signal received, the number of people on board, and any other relevant information. This message is intended to help facilitate search and rescue operations by providing timely and accurate information about the aircraft's position and status. It is typically transmitted by the aircraft itself or by a rescue coordination center on behalf of the aircraft.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Altitude Integer
Sog Double
PositionAccuracy Boolean
Longitude Double
Latitude Double
Cog Double
Timestamp Integer
AltFromBaro Boolean
Spare1 Integer
Dte Boolean
Spare2 Integer
AssignedMode Boolean
Raim Boolean
CommunicationStateIsItdma Boolean
CommunicationState Integer
StandardSearchAndRescueAircraftReport

{
  "AltFromBaro": false,
  "Altitude": 19,
  "AssignedMode": false,
  "Cog": 311.5,
  "CommunicationState": 49152,
  "CommunicationStateIsItdma": false,
  "Dte": true,
  "Latitude": 38.47499666666667,
  "Longitude": -8.871578333333334,
  "MessageID": 9,
  "PositionAccuracy": true,
  "Raim": true,
  "RepeatIndicator": 0,
  "Sog": 0,
  "Spare1": 0,
  "Spare2": 0,
  "Timestamp": 7,
  "UserID": 266125000,
  "Valid": true
}

StaticDataReport

An StaticDataReport AIS message is a message transmitted by a vessel to provide information about its static or non-dynamic characteristics. This message includes information such as the vessel's name, dimensions, type of cargo, and the maximum number of persons on board. It also includes the vessel's unique identifier, called the Maritime Mobile Service Identity (MMSI), as well as the vessel's call sign, if applicable. This message is transmitted every six hours or upon request by a vessel or coastal station. It is used by other vessels and coastal stations to identify and locate the transmitting vessel, as well as to understand its capabilities and intentions.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Reserved Integer
PartNumber Boolean
ReportA StaticDataReport_ReportA
ReportB StaticDataReport_ReportB
StaticDataReport

{
  "MessageID": 24,
  "PartNumber": true,
  "RepeatIndicator": 0,
  "ReportA": {
    "Name": "",
    "Valid": false
  },
  "ReportB": {
    "CallSign": "LESW",
    "Dimension": {
      "A": 12,
      "B": 3,
      "C": 3,
      "D": 2
    },
    "FixType": 0,
    "ShipType": 37,
    "Spare": 0,
    "Valid": true,
    "VenderIDModel": 1,
    "VenderIDSerial": 292978,
    "VendorIDName": "SRT"
  },
  "Reserved": 0,
  "UserID": 257702970,
  "Valid": true
}

SingleSlotBinaryMessage

A SingleSlotBinaryMessage AIS message contains binary data in a single slot. This message is used to convey information about the vessel's position, course, speed, and other relevant details to nearby vessels and AIS receivers. It is typically transmitted every two to six minutes, depending on the vessel's speed and location. The message is transmitted using a VHF radio frequency and is limited to a maximum of 280 bits of data. The data is encoded in a binary format and is divided into various fields, each of which represents a different piece of information about the vessel. Some examples of the data that may be included in a SingleSlotBinaryMessage AIS message are the vessel's identification number, location, course, speed, and heading.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
DestinationIDValid Boolean
ApplicationIDValid Boolean
DestinationID Integer
Spare Integer
ApplicationID AddressedBinaryMessage_ApplicationID
Payload String
SingleSlotBinaryMessage

{
  "ApplicationID": {
    "DesignatedAreaCode": 247,
    "FunctionIdentifier": 59,
    "Valid": true
  },
  "ApplicationIDValid": true,
  "DestinationID": 0,
  "DestinationIDValid": false,
  "MessageID": 25,
  "Payload": "",
  "RepeatIndicator": 0,
  "Spare": 0,
  "UserID": 247155300,
  "Valid": true
}

Interrogation

An Interrogation AIS message is used to request information from other nearby vessels. This message typically includes the vessel's identification, position, and course information, as well as a request for any other relevant data such as speed, heading, or intended route. The Interrogation AIS message is typically transmitted on a specific frequency and may be repeated several times in order to ensure that it is received by all nearby vessels. The purpose of this message is to gather information about the surrounding vessels and to improve situational awareness and collision avoidance for the transmitting vessel.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare Integer
Station1Msg1 Interrogation_Station1Msg1
Station1Msg2 Interrogation_Station1Msg2
Station2 Interrogation_Station2
Interrogation

{
  "MessageID": 15,
  "RepeatIndicator": 0,
  "Spare": 0,
  "Station1Msg1": {
    "MessageID": 431006614,
    "SlotOffset": 0,
    "StationID": 431006614,
    "Valid": true
  },
  "Station1Msg2": {
    "MessageID": 3,
    "SlotOffset": 0,
    "Spare": 0,
    "Valid": false
  },
  "Station2": {
    "MessageID": 0,
    "SlotOffset": 0,
    "Spare1": 0,
    "Spare2": 0,
    "StationID": 0,
    "Valid": false
  },
  "UserID": 4310211,
  "Valid": true
}

LongRangeAisBroadcastMessage

A LongRangeAisBroadcastMessage AIS message is intended for long-range communication, allowing vessels to communicate with each other over distances of up to 20 nautical miles. The LongRangeAisBroadcastMessage AIS message includes information about the vessel's location, course, speed, and other relevant details. This message is transmitted at regular intervals, typically every two to five minutes, and can be received by other vessels or AIS base stations within range.

The LongRangeAisBroadcastMessage AIS message is an important tool for improving maritime safety, as it allows vessels to share information about their location and movements with other vessels in the area. This can help to prevent collisions, as well as provide an important source of information for search and rescue operations.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
PositionAccuracy Boolean
Raim Boolean
NavigationalStatus Integer
Longitude Double
Latitude Double
Sog Double
Cog Double
PositionLatency Boolean
Spare Boolean
LongRangeAisBroadcastMessage

{
  "Latitude1": 55.74,
  "Latitude2": 55.64333333333333,
  "Longitude1": 21.168333333333333,
  "Longitude2": 20.975,
  "MessageID": 23,
  "QuietTime": 0,
  "RepeatIndicator": 0,
  "ReportingInterval": 11,
  "ShipType": 0,
  "Spare1": 0,
  "Spare2": 0,
  "Spare3": 0,
  "StationType": 0,
  "TxRxMode": 0,
  "UserID": 2770030,
  "Valid": true
}

GnssBroadcastBinaryMessage

An GnssBroadcastBinaryMessage AIS message is a type of message transmitted by a Global Navigation Satellite System (GNSS) broadcaster, such as the Global Positioning System (GPS) or the European Geostationary Navigation Overlay Service (EGNOS). It contains binary data that can be used by GNSS receivers to improve the accuracy of their positioning and navigation solutions.

This message typically includes information such as the broadcast ephemeris (precise orbit data for each satellite) and almanac (general satellite data), as well as any correction data or other data needed to improve the accuracy of the GNSS signal. It may also contain data on the ionosphere, which is the layer of Earth's atmosphere that affects the accuracy of GNSS signals.

The GnssBroadcastBinaryMessage AIS message is typically transmitted in a standard format, allowing it to be easily decoded by GNSS receivers. It is an important tool for maintaining the accuracy and reliability of GNSS systems, and is used by a wide range of applications, including aviation, marine navigation, and land-based transportation.
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare1 Integer
Longitude Double
Latitude Double
Spare2 Integer
Data String
DataLinkManagementMessage
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare Integer
Data DataLinkManagementMessage_Data
DataLinkManagementMessage

{
  "Data": {
    "0": {
      "Increment": 750,
      "Offset": 623,
      "TimeOut": 7,
      "Valid": false,
      "integerOfSlots": 1
    },
    "1": {
      "Increment": 1125,
      "Offset": 1125,
      "TimeOut": 7,
      "Valid": false,
      "integerOfSlots": 1
    },
    "2": {
      "Increment": 0,
      "Offset": 0,
      "TimeOut": 0,
      "Valid": false,
      "integerOfSlots": 0
    },
    "3": {
      "Increment": 0,
      "Offset": 0,
      "TimeOut": 0,
      "Valid": false,
      "integerOfSlots": 0
    }
  },
  "MessageID": 20,
  "RepeatIndicator": 0,
  "Spare": 0,
  "UserID": 2655069,
  "Valid": true
}

AddressedSafetyMessage
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Sequenceinteger Integer
DestinationID Integer
Retransmission Boolean
Spare Boolean
Text String
AddressedSafetyMessage

AddressedBinaryMessage
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Sequenceinteger Integer
DestinationID Integer
Retransmission Boolean
Spare Boolean
ApplicationID AddressedBinaryMessage_ApplicationID
BinaryData String
AddressedBinaryMessage

{
  "ApplicationID": {
    "DesignatedAreaCode": 1,
    "FunctionIdentifier": 40,
    "Valid": true
  },
  "BinaryData": "",
  "DestinationID": 2442131,
  "MessageID": 6,
  "RepeatIndicator": 0,
  "Retransmission": true,
  "Sequenceinteger": 1,
  "Spare": false,
  "UserID": 538006462,
  "Valid": true
}

CoordinatedUTCInquiry
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare1 Integer
DestinationID Integer
Spare2 Integer
BinaryAcknowledge
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare Integer
Destinations BinaryAcknowledge_Destinations
BinaryAcknowledge

{
  "Destinations": {
    "0": {
      "DestinationID": 992351360,
      "Sequenceinteger": 0,
      "Valid": true
    },
    "1": {
      "DestinationID": 0,
      "Sequenceinteger": 0,
      "Valid": false
    },
    "2": {
      "DestinationID": 0,
      "Sequenceinteger": 0,
      "Valid": false
    },
    "3": {
      "DestinationID": 0,
      "Sequenceinteger": 0,
      "Valid": false
    }
  },
  "MessageID": 7,
  "RepeatIndicator": 0,
  "Spare": 0,
  "UserID": 2320075,
  "Valid": true
}

ChannelManagement
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare1 Integer
ChannelA Integer
ChannelB Integer
TxRxMode Integer
LowPower Boolean
Area ChannelManagement_Area
Unicast ChannelManagement_Unicast
IsAddressed Boolean
BwA Boolean
BwB Boolean
TransitionalZoneSize Integer
Spare4 Integer
ChannelManagement

{
  "Area": {
    "Latitude1": 23.31,
    "Latitude2": 21.93166666666667,
    "Longitude1": 120.88166666666666,
    "Longitude2": 119.42166666666667
  },
  "BwA": false,
  "BwB": false,
  "ChannelA": 2087,
  "ChannelB": 2088,
  "IsAddressed": false,
  "LowPower": false,
  "MessageID": 22,
  "RepeatIndicator": 0,
  "Spare1": 0,
  "Spare4": 0,
  "TransitionalZoneSize": 4,
  "TxRxMode": 0,
  "Unicast": {
    "AddressStation1": 0,
    "AddressStation2": 0,
    "Spare2": 0,
    "Spare3": 0
  },
  "UserID": 4163400,
  "Valid": true
}

AssignedModeCommand
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Spare Integer
Commands AssignedModeCommand_Commands
AssignedModeCommand

{
  "Commands": {
    "0": {
      "DestinationID": 249362000,
      "Increment": 0,
      "Offset": 20,
      "Valid": true
    },
    "1": {
      "DestinationID": 249362000,
      "Increment": 0,
      "Offset": 20,
      "Valid": true
    }
  },
  "MessageID": 16,
  "RepeatIndicator": 0,
  "Spare": 0,
  "UserID": 2320844,
  "Valid": true
}

AidsToNavigationReport

An AidsToNavigationReport AIS message is a type of maritime communication used by ships to report on the location and status of navigational aids, such as buoys, lighthouses, and other markers. This message includes information about the type and location of the aid, as well as any relevant details such as its height, range, and color. It may also include information about the current status of the aid, such as whether it is operational or out of service. This message is typically transmitted using the Automatic Identification System (AIS), a system designed to help ships communicate with one another and with land-based stations. The AidsToNavigationReport message is used to ensure that ships are aware of the location and status of important navigational aids in their area, helping them to safely navigate through shipping lanes and avoid collisions
Attributes
MessageID Integer
RepeatIndicator Integer
UserID Integer
Valid Boolean
Type Integer
Name String
PositionAccuracy Boolean
Longitude Double
Latitude Double
Dimension ShipStaticData_Dimension
Fixtype Integer
Timestamp Integer
OffPosition Boolean
AtoN Integer
Raim Boolean
VirtualAtoN Boolean
AssignedMode Boolean
Spare Boolean
NameExtension String
AidsToNavigationReport

{
  "AssignedMode": false,
  "AtoN": 0,
  "Dimension": {
    "A": 0,
    "B": 0,
    "C": 0,
    "D": 0
  },
  "Fixtype": 7,
  "Latitude": 30.099798333333336,
  "Longitude": -90.91296166666666,
  "MessageID": 21,
  "Name": "B                   ",
  "NameExtension": "",
  "OffPosition": false,
  "PositionAccuracy": false,
  "Raim": false,
  "RepeatIndicator": 0,
  "Spare": false,
  "Timestamp": 61,
  "Type": 26,
  "UserID": 993682816,
  "Valid": true,
  "VirtualAtoN": true
}

AIS Stream

    Privacy Policy
    Contact

© 2022 AIS Stream™. All Rights Reserved.

