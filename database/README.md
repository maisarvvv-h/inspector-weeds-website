# Inspector Weeds Database

This directory contains the MySQL database schema used by the **Inspector Weeds** web application.

The database supports the storage of uploaded plantation images, DeepLabV3+ analysis results, treatment-planning information, and supporting website data.

## Database

**Database name:** `inspector_weeds`

The database was developed using MySQL/MariaDB through phpMyAdmin and XAMPP.

## Tables

### `images`

Stores information about each plantation image uploaded to the application.

The table records:

- unique image ID;
- image filename;
- original image path;
- processed overlay path;
- segmentation mask path;
- plantation zone;
- model used;
- analysis status; and
- analysis date.

The `analysis_status` field tracks the image through the inference workflow using:

```text
Uploaded
Processing
Completed
Failed
```

The `image_id` field is unique and is used to associate an uploaded image with its corresponding detection result.

---

### `detection_results`

Stores the outputs produced after DeepLabV3+ inference.

The table includes:

- image ID;
- image filename;
- plantation zone;
- severity level;
- weed coverage percentage;
- weed pixel count;
- total pixel count;
- average model confidence;
- processed overlay path;
- segmentation mask path;
- model used;
- manual review status;
- optional recommendation; and
- upload timestamp.

The `image_id` field links each detection result to the corresponding record in the `images` table.

The database uses a foreign-key relationship:

```text
images.image_id
       ↓
detection_results.image_id
```

If an image record is deleted, its associated detection result is also removed through `ON DELETE CASCADE`.

---

### `treatment_plans`

Stores zone-level treatment-planning information generated from analysed plantation images.

Each record represents a plantation zone and includes information such as:

- number of analysed trees;
- number of trees requiring treatment;
- number of infested trees;
- average weed coverage;
- maximum weed coverage;
- average model confidence;
- equivalent treatment area;
- recommended herbicide;
- application rate;
- herbicide requirements;
- water requirements;
- UAV labour requirements;
- herbicide cost;
- water cost;
- UAV labour cost;
- total estimated treatment cost;
- priority level; and
- recommended action.

The `zone_id` field is unique so that each plantation zone maintains one treatment-plan record that can be updated as additional images are analysed.

---

## Application Data Flow

```text
Plantation Image Upload
          ↓
       images
          ↓
 DeepLabV3+ Inference
          ↓
  detection_results
          ↓
 Dashboard Aggregation
          ↓
   treatment_plans
          ↓
 Treatment Planning
```

When an image is uploaded, an entry is first created in the `images` table.

The application then performs DeepLabV3+ semantic segmentation. Once inference is complete, the resulting weed coverage, severity, confidence, segmentation paths, and related information are stored in `detection_results`.

These image-level results are subsequently aggregated by plantation zone and used by the Dashboard and Treatment Planning components.

## Key Relationships

```text
images
  │
  │ image_id
  ↓
detection_results


detection_results
  │
  │ aggregated by zone_id
  ↓
treatment_plans
```

The first relationship is enforced through a database foreign key.

Zone-level treatment information is derived by the application from detection results associated with each plantation zone.

## Local Setup

1. Start **Apache** and **MySQL** using XAMPP.
2. Open phpMyAdmin.
3. Create a database named:

```text
inspector_weeds
```

4. Select the database.
5. Open the **Import** tab.
6. Import:

```text
schema.sql
```

7. Configure the application's local database connection settings.

The application will populate the database as plantation images are uploaded and analysed.

## Notes

The SQL file included in this repository contains the **database schema only**.

Uploaded-image records, model inference results, treatment-plan records, user credentials, and other local test data are intentionally excluded from the public repository.
