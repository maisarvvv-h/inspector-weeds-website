import argparse
import json
import os
import sys
import time

dependency_import_started_at = time.perf_counter()

import albumentations as A
import cv2
import numpy as np
import segmentation_models_pytorch as smp
import torch
from albumentations.pytorch import ToTensorV2


BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(BASE_DIR, "model", "best_deeplabv3plus.pt")
PROCESSED_FOLDER = os.path.join(BASE_DIR, "processed")
IMG_SIZE = 1024
DEFAULT_BATCH_SIZE = 4

BACKGROUND_CLASS_ID = 0
WEED_CLASS_ID = 1
TRUNK_CLASS_ID = 2

BACKGROUND_COLOUR = [0, 0, 0]
WEED_COLOUR = [0, 255, 0]
TRUNK_COLOUR = [139, 69, 19]


def log(message):
    print(message, file=sys.stderr)


def parse_arguments():
    parser = argparse.ArgumentParser(description="Run DeepLabV3+ inference.")
    parser.add_argument("image_path", nargs="?")
    parser.add_argument("--batch-manifest")
    parser.add_argument("--batch-size", type=int, default=DEFAULT_BATCH_SIZE)
    parser.add_argument("--confidence", type=float, default=0.25)
    return parser.parse_args()


def zone_from_filename(filename):
    upper_name = os.path.splitext(os.path.basename(filename))[0].upper()
    for zone in ("A", "B", "C", "D", "E"):
        if upper_name.startswith(zone):
            return "Zone " + zone
    return "Unknown"


def load_model():
    if not os.path.isfile(MODEL_PATH):
        raise FileNotFoundError("DeepLab checkpoint was not found: " + MODEL_PATH)

    checkpoint = torch.load(MODEL_PATH, map_location=DEVICE, weights_only=False)
    required_keys = ["class_names", "encoder", "model_state_dict"]
    missing_keys = [key for key in required_keys if key not in checkpoint]
    if missing_keys:
        raise KeyError("The checkpoint is missing required keys: " + ", ".join(missing_keys))

    class_names = checkpoint["class_names"]
    if len(class_names) != 3:
        raise ValueError("The checkpoint must contain exactly three classes.")

    model = smp.DeepLabV3Plus(
        encoder_name=checkpoint["encoder"],
        encoder_weights=None,
        in_channels=3,
        classes=len(class_names),
        activation=None,
    )
    model.load_state_dict(checkpoint["model_state_dict"])
    model.to(DEVICE)
    model.eval()
    return model


def analyse_one(model, transform, entry):
    image_path = os.path.abspath(str(entry["image_path"]))
    image_id = str(entry.get("image_id") or os.path.splitext(os.path.basename(image_path))[0])

    image_bgr = cv2.imread(image_path)
    if image_bgr is None:
        raise FileNotFoundError("Cannot find or read image: " + image_path)

    original_bgr = image_bgr.copy()
    original_height, original_width = original_bgr.shape[:2]
    image_rgb = cv2.cvtColor(original_bgr, cv2.COLOR_BGR2RGB)
    input_tensor = transform(image=image_rgb)["image"].unsqueeze(0)

    with torch.inference_mode():
        output = model(input_tensor.to(DEVICE))
        probabilities = torch.softmax(output, dim=1)
        prediction_tensor = torch.argmax(probabilities, dim=1)

    prediction = prediction_tensor.squeeze(0).cpu().numpy().astype(np.uint8)
    probability_map = probabilities.squeeze(0).cpu().numpy()

    background_mask = prediction == BACKGROUND_CLASS_ID
    weed_mask = prediction == WEED_CLASS_ID
    trunk_mask = prediction == TRUNK_CLASS_ID

    total_pixels = int(prediction.size)
    background_pixels = int(np.sum(background_mask))
    weed_pixels = int(np.sum(weed_mask))
    trunk_pixels = int(np.sum(trunk_mask))

    background_coverage = background_pixels / total_pixels * 100 if total_pixels else 0.0
    weed_coverage = weed_pixels / total_pixels * 100 if total_pixels else 0.0
    trunk_coverage = trunk_pixels / total_pixels * 100 if total_pixels else 0.0
    severity = "LOW" if weed_coverage < 5 else "HIGH"

    if weed_pixels > 0:
        confidence_score = float(np.mean(probability_map[WEED_CLASS_ID][weed_mask]) * 100)
    else:
        confidence_score = 0.0

    colour_mask_rgb = np.zeros((IMG_SIZE, IMG_SIZE, 3), dtype=np.uint8)
    colour_mask_rgb[background_mask] = BACKGROUND_COLOUR
    colour_mask_rgb[weed_mask] = WEED_COLOUR
    colour_mask_rgb[trunk_mask] = TRUNK_COLOUR

    prediction_original = cv2.resize(
        prediction,
        (original_width, original_height),
        interpolation=cv2.INTER_NEAREST,
    )
    colour_mask_original_rgb = cv2.resize(
        colour_mask_rgb,
        (original_width, original_height),
        interpolation=cv2.INTER_NEAREST,
    )

    weed_mask_original = prediction_original == WEED_CLASS_ID
    trunk_mask_original = prediction_original == TRUNK_CLASS_ID
    foreground_mask_original = weed_mask_original | trunk_mask_original

    original_rgb = cv2.cvtColor(original_bgr, cv2.COLOR_BGR2RGB)
    blended_rgb = cv2.addWeighted(original_rgb, 0.65, colour_mask_original_rgb, 0.35, 0)
    overlay_rgb = original_rgb.copy()
    overlay_rgb[foreground_mask_original] = blended_rgb[foreground_mask_original]

    os.makedirs(PROCESSED_FOLDER, exist_ok=True)
    name = os.path.splitext(os.path.basename(image_path))[0]
    overlay_filename = name + "_overlay.png"
    mask_filename = name + "_mask.png"
    overlay_absolute_path = os.path.join(PROCESSED_FOLDER, overlay_filename)
    mask_absolute_path = os.path.join(PROCESSED_FOLDER, mask_filename)

    overlay_saved = cv2.imwrite(
        overlay_absolute_path,
        cv2.cvtColor(overlay_rgb, cv2.COLOR_RGB2BGR),
    )
    mask_saved = cv2.imwrite(
        mask_absolute_path,
        cv2.cvtColor(colour_mask_original_rgb, cv2.COLOR_RGB2BGR),
    )
    if not overlay_saved:
        raise IOError("Failed to save overlay: " + overlay_absolute_path)
    if not mask_saved:
        raise IOError("Failed to save mask: " + mask_absolute_path)

    zone = str(entry.get("zone") or zone_from_filename(image_path))
    if zone == "Unknown":
        zone = zone_from_filename(image_path)

    return {
        "image_id": image_id,
        "status": "success",
        "zone": zone,
        "model_used": "DeepLabV3+",
        "processed_image_path": "processed/" + overlay_filename,
        "mask_path": "processed/" + mask_filename,
        "weed_coverage": round(float(weed_coverage), 2),
        "weed_pixels": weed_pixels,
        "total_pixels": total_pixels,
        "severity_level": severity,
        "confidence_score": round(float(confidence_score), 2),
        "background_coverage": round(float(background_coverage), 2),
        "trunk_coverage": round(float(trunk_coverage), 2),
        "background_pixels": background_pixels,
        "trunk_pixels": trunk_pixels,
    }


def main():
    arguments = parse_arguments()
    batch_size = max(1, arguments.batch_size)

    if arguments.batch_manifest:
        with open(arguments.batch_manifest, "r", encoding="utf-8") as manifest_file:
            entries = json.load(manifest_file)
        if not isinstance(entries, list):
            raise ValueError("Batch manifest must contain a JSON list.")
    elif arguments.image_path:
        entries = [{
            "image_id": os.path.splitext(os.path.basename(arguments.image_path))[0],
            "image_path": arguments.image_path,
            "zone": zone_from_filename(arguments.image_path),
        }]
    else:
        entries = [{
            "image_id": "test_image",
            "image_path": os.path.join(BASE_DIR, "uploads", "test_image.jpg"),
            "zone": "Unknown",
        }]

    model_load_started_at = time.perf_counter()
    model = load_model()
    model_load_seconds = time.perf_counter() - model_load_started_at

    transform = A.Compose([
        A.Resize(height=IMG_SIZE, width=IMG_SIZE),
        A.Normalize(
            mean=(0.485, 0.456, 0.406),
            std=(0.229, 0.224, 0.225),
        ),
        ToTensorV2(),
    ])

    results = []
    inference_started_at = time.perf_counter()

    for batch_start in range(0, len(entries), batch_size):
        batch_entries = entries[batch_start:batch_start + batch_size]
        tensors = []
        valid_positions = []

        for position, entry in enumerate(batch_entries):
            image_path = os.path.abspath(str(entry["image_path"]))
            try:
                image_bgr = cv2.imread(image_path)
                if image_bgr is None:
                    raise FileNotFoundError("Cannot find or read image: " + image_path)
                image_rgb = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2RGB)
                tensors.append(transform(image=image_rgb)["image"].unsqueeze(0))
                valid_positions.append(position)
            except Exception as error:
                results.append({
                    "image_id": str(entry.get("image_id") or os.path.splitext(os.path.basename(image_path))[0]),
                    "status": "error",
                    "error": str(error),
                })

        if tensors:
            try:
                tensor_batch = torch.cat(tensors, dim=0).to(DEVICE)
                with torch.inference_mode():
                    outputs = model(tensor_batch)
                    probabilities = torch.softmax(outputs, dim=1)
                    prediction_tensors = torch.argmax(probabilities, dim=1)

                for position, prediction_tensor, probability_tensor in zip(
                    valid_positions,
                    prediction_tensors,
                    probabilities,
                ):
                    entry = batch_entries[position]
                    image_path = os.path.abspath(str(entry["image_path"]))
                    try:
                        image_bgr = cv2.imread(image_path)
                        if image_bgr is None:
                            raise FileNotFoundError("Cannot find or read image: " + image_path)
                        result = analyse_prediction(
                            model,
                            entry,
                            image_bgr,
                            prediction_tensor,
                            probability_tensor,
                        )
                        results.append(result)
                    except Exception as error:
                        results.append({
                            "image_id": str(entry.get("image_id") or os.path.splitext(os.path.basename(image_path))[0]),
                            "status": "error",
                            "error": str(error),
                        })
            except Exception as error:
                for position in valid_positions:
                    entry = batch_entries[position]
                    results.append({
                        "image_id": str(entry.get("image_id") or os.path.splitext(os.path.basename(str(entry["image_path"])))[0]),
                        "status": "error",
                        "error": str(error),
                    })

    inference_seconds = time.perf_counter() - inference_started_at
    average_seconds = inference_seconds / len(entries) if entries else 0.0

    log("Device: " + str(DEVICE))
    log("Model load: {:.2f}s".format(model_load_seconds))
    log("Images processed: " + str(len(entries)))
    log("Batch size: " + str(batch_size))
    log("Inference time: {:.2f}s".format(inference_seconds))
    log("Average: {:.2f}s/image".format(average_seconds))
    log("TIMING model_init_seconds={:.3f}".format(model_load_seconds))
    log("TIMING dependency_import_seconds={:.3f}".format(
        time.perf_counter() - dependency_import_started_at - model_load_seconds
    ))
    log("TIMING inference_seconds={:.3f}".format(inference_seconds))

    print(json.dumps({
        "status": "success",
        "results": results,
    }))


def analyse_prediction(model, entry, original_bgr, prediction_tensor, probability_tensor):
    image_path = os.path.abspath(str(entry["image_path"]))
    original_height, original_width = original_bgr.shape[:2]
    prediction = prediction_tensor.cpu().numpy().astype(np.uint8)
    probability_map = probability_tensor.cpu().numpy()

    background_mask = prediction == BACKGROUND_CLASS_ID
    weed_mask = prediction == WEED_CLASS_ID
    trunk_mask = prediction == TRUNK_CLASS_ID

    total_pixels = int(prediction.size)
    background_pixels = int(np.sum(background_mask))
    weed_pixels = int(np.sum(weed_mask))
    trunk_pixels = int(np.sum(trunk_mask))
    background_coverage = background_pixels / total_pixels * 100 if total_pixels else 0.0
    weed_coverage = weed_pixels / total_pixels * 100 if total_pixels else 0.0
    trunk_coverage = trunk_pixels / total_pixels * 100 if total_pixels else 0.0
    severity = "LOW" if weed_coverage < 5 else "HIGH"

    confidence_score = (
        float(np.mean(probability_map[WEED_CLASS_ID][weed_mask]) * 100)
        if weed_pixels > 0 else 0.0
    )

    colour_mask_rgb = np.zeros((IMG_SIZE, IMG_SIZE, 3), dtype=np.uint8)
    colour_mask_rgb[background_mask] = BACKGROUND_COLOUR
    colour_mask_rgb[weed_mask] = WEED_COLOUR
    colour_mask_rgb[trunk_mask] = TRUNK_COLOUR

    prediction_original = cv2.resize(
        prediction,
        (original_width, original_height),
        interpolation=cv2.INTER_NEAREST,
    )
    colour_mask_original_rgb = cv2.resize(
        colour_mask_rgb,
        (original_width, original_height),
        interpolation=cv2.INTER_NEAREST,
    )
    weed_mask_original = prediction_original == WEED_CLASS_ID
    trunk_mask_original = prediction_original == TRUNK_CLASS_ID
    foreground_mask_original = weed_mask_original | trunk_mask_original

    original_rgb = cv2.cvtColor(original_bgr, cv2.COLOR_BGR2RGB)
    blended_rgb = cv2.addWeighted(original_rgb, 0.65, colour_mask_original_rgb, 0.35, 0)
    overlay_rgb = original_rgb.copy()
    overlay_rgb[foreground_mask_original] = blended_rgb[foreground_mask_original]

    os.makedirs(PROCESSED_FOLDER, exist_ok=True)
    name = os.path.splitext(os.path.basename(image_path))[0]
    overlay_filename = name + "_overlay.png"
    mask_filename = name + "_mask.png"
    overlay_absolute_path = os.path.join(PROCESSED_FOLDER, overlay_filename)
    mask_absolute_path = os.path.join(PROCESSED_FOLDER, mask_filename)

    if not cv2.imwrite(overlay_absolute_path, cv2.cvtColor(overlay_rgb, cv2.COLOR_RGB2BGR)):
        raise IOError("Failed to save overlay: " + overlay_absolute_path)
    if not cv2.imwrite(mask_absolute_path, cv2.cvtColor(colour_mask_original_rgb, cv2.COLOR_RGB2BGR)):
        raise IOError("Failed to save mask: " + mask_absolute_path)

    zone = str(entry.get("zone") or "Unknown")
    if zone == "Unknown":
        zone = zone_from_filename(image_path)

    image_id = str(entry.get("image_id") or os.path.splitext(os.path.basename(image_path))[0])
    return {
        "image_id": image_id,
        "status": "success",
        "zone": zone,
        "model_used": "DeepLabV3+",
        "processed_image_path": "processed/" + overlay_filename,
        "mask_path": "processed/" + mask_filename,
        "weed_coverage": round(float(weed_coverage), 2),
        "weed_pixels": weed_pixels,
        "total_pixels": total_pixels,
        "severity_level": severity,
        "confidence_score": round(float(confidence_score), 2),
        "background_coverage": round(float(background_coverage), 2),
        "trunk_coverage": round(float(trunk_coverage), 2),
        "background_pixels": background_pixels,
        "trunk_pixels": trunk_pixels,
    }


DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")

if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        log("Error: " + str(error))
        print(json.dumps({
            "status": "error",
            "results": [],
            "error": str(error),
        }))
