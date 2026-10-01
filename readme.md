# OpenAIRE Plugin

## About
The plugin is based mainly on the plugin https://github.com/ojsde/openAIRE.

It is an alternation of the XML generator of the plugin and creating now metadata_prefix "oai_openaire". Now it completly fullfills the guideline v4 for literature from OpenAIRE https://openaire-guidelines-for-literature-repository-managers.readthedocs.io/en/v4.0.0/application_profile.html. You can use validator for this XML. It follows the OpenAIRE XSD rules and can be scaled by this rules more: https://github.com/openaire/guidelines-literature-repositories/tree/master/schemas/4.0

As of version 3.5.0-1, this plugin also provides the JATS XML metadata format (metadata_prefix "oai_openaire_jats") that was previously offered by the separate https://github.com/ojsde/openAIRE plugin. Enabling this plugin now covers both formats. The two plugins should not be enabled at the same time on the same journal: both independently add "Resource Type"/"Audience" fields to the section settings form, so enabling both would show duplicate fields there.

As of version 3.6.0-0, the "oai_openaire_jats" format reuses the [jatsTemplate](https://github.com/pkp/jatsTemplate) plugin's own JATS generation instead of building its XML independently, and layers OpenAIRE/COAR-specific metadata on top. **The jatsTemplate plugin must be enabled** for "oai_openaire_jats" to work; if it isn't, that format returns an OAI-PMH `cannotDisseminateFormat` error. The "oai_openaire" (COAR/DataCite) format is unaffected and has no such dependency.

## License
This plugin is licensed under the GNU General Public License v3. See the file LICENSE for the complete terms of this license.

## System Requirements
OJS 3.6.0.x.
PHP 8.2 or greater.

## Installation
Please install this plugin via the Plugin Gallery rather than copying the files in manually. If you are switching over from the openAIREstandard plugin, installing via the Plugin Gallery ensures any leftover openAIREstandard data is cleaned up automatically as part of the install.

## Example
https://cyberpsychology.eu/oai?verb=ListRecords&metadataPrefix=oai_openaire

The JATS format is available the same way, at the same OAI endpoint, using `metadataPrefix=oai_openaire_jats` instead.

# Credit
---------------
This plugin was developed at the [Masaryk University Press - Munipress](https://www.press.muni.cz), as part of its active participation in the [Craft-OA project](https://www.craft-oa.eu/).

The development was initiated, coordinated, and technically supported by Munipress.

# Academic-journals.cz Workspace
--------------
Academic-journals.cz is a collaborative workspace for the development and management of the Academic Journals platform for Czech scholarly journals. The project aims to support Czech academic publishers and editorial teams by providing shared infrastructure and tools for publishing and managing academic journals.

The project is based on an open memorandum of cooperation between **Charles University** and **Palacký University Olomouc**, under the leadership of **Karolinum Press** and **Palacký University Press**.

The memorandum establishes a shared framework for cooperation in the field of academic journal publishing infrastructure and services. It is designed as an open initiative, allowing other universities and academic institutions to join and participate in the development of the platform.

Technical garant for the project is **Radek Gomola**.