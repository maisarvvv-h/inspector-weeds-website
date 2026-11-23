import os
import sys
import json
import time
 
dependency_import_started_at = time.perf_counter()

import cv2
import numpy as np
 
import torch
import segmentation_models_pytorch as smp
 
import albumentations as A
from albumentations.pytorch import ToTensorV2
 
dependency_import_seconds = time.perf_counter() - dependency_import_started_at
print(f"TIMING dependency_import_seconds={dependency_import_seconds:.3f}")

 
# =====================================================
# SETTINGS
# =====================================================
 
BASE_DIR = os.path.dirname(
    os.path.abspath(__file__)
)
 
DEVICE = torch.device(
    "cuda" if torch.cuda.is_available() else "cpu"
)
 
MODEL_PATH = os.path.join(
    BASE_DIR,
    "model",
    "best_deeplabv3plus.pt"
)
 
PROCESSED_FOLDER = os.path.join(
    BASE_DIR,
    "processed"
)
 
IMG_SIZE = 1024
 
BACKGROUND_CLASS_ID = 0
WEED_CLASS_ID = 1
TRUNK_CLASS_ID = 2
 
 
# RGB colours used for visualisation
BACKGROUND_COLOUR = [0, 0, 0]
WEED_COLOUR = [0, 255, 0]
TRUNK_COLOUR = [139, 69, 19]
 
 
# =====================================================
# LOAD CHECKPOINT
# =====================================================
 
model_init_started_at = time.perf_counter()

print("Loading checkpoint...")
 
if not os.path.isfile(MODEL_PATH):
    raise FileNotFoundError(
        f"DeepLab checkpoint was not found:\n{MODEL_PATH}"
    )
 
checkpoint = torch.load(
    MODEL_PATH,
    map_location=DEVICE,
    weights_only=False
)
 
print("Checkpoint loaded successfully!")
 
required_checkpoint_keys = [
    "class_names",
    "encoder",
    "model_state_dict"
]
 
for key in required_checkpoint_keys:
    if key not in checkpoint:
        raise KeyError(
            f"The checkpoint is missing the required key: {key}"
        )
 
CLASS_NAMES = checkpoint["class_names"]
 
if len(CLASS_NAMES) != 3:
    raise ValueError(
        "This script expects exactly three classes: "
        "Background, Ground Weeds and Palm Oil Tree Trunk."
    )
 
print("Creating model...")
 
model = smp.DeepLabV3Plus(
    encoder_name=checkpoint["encoder"],
    encoder_weights=None,
    in_channels=3,
    classes=len(CLASS_NAMES),
    activation=None
)
 
print("Model architecture created!")
 
model.load_state_dict(
    checkpoint["model_state_dict"]
)
 
print("Weights loaded successfully!")
 
model.to(DEVICE)
model.eval()
 
model_init_seconds = time.perf_counter() - model_init_started_at
print(f"TIMING model_init_seconds={model_init_seconds:.3f}")

print("=" * 45)
print("DeepLabV3+ Model Ready!")
print("=" * 45)
 
print("\nClasses:")
 
for index, class_name in enumerate(CLASS_NAMES):
    print(f"{index}: {class_name}")
 
 
# =====================================================
# READ IMAGE PATH
# =====================================================
 
if len(sys.argv) > 1:
    image_path = sys.argv[1]
else:
    image_path = os.path.join(
        BASE_DIR,
        "uploads",
        "test_image.jpg"
    )
 
image_path = os.path.abspath(image_path)
 
print("\nReading image:")
print(image_path)
 
 
# =====================================================
# READ IMAGE
# =====================================================
 
image_bgr = cv2.imread(image_path)
 
if image_bgr is None:
    raise FileNotFoundError(
        f"Cannot find or read image:\n{image_path}"
    )
 
original_bgr = image_bgr.copy()
 
original_height, original_width = (
    original_bgr.shape[:2]
)
 
image_rgb = cv2.cvtColor(
    image_bgr,
    cv2.COLOR_BGR2RGB
)
 
 
# =====================================================
# PREPROCESSING
# =====================================================
 
transform = A.Compose(
    [
        A.Resize(
            height=IMG_SIZE,
            width=IMG_SIZE
        ),
 
        A.Normalize(
            mean=(0.485, 0.456, 0.406),
            std=(0.229, 0.224, 0.225)
        ),
 
        ToTensorV2()
    ]
)
 
transformed = transform(
    image=image_rgb
)
 
input_tensor = transformed["image"]
 
input_tensor = input_tensor.unsqueeze(0)
 
input_tensor = input_tensor.to(DEVICE)
 
print("\nInput tensor shape:")
print(input_tensor.shape)
 
 
# =====================================================
# RUN DEEPLAB INFERENCE
# =====================================================
 
print("\nRunning DeepLab inference...")
 
inference_started_at = time.perf_counter()

with torch.no_grad():
    outputs = model(input_tensor)
 
    probabilities = torch.softmax(
        outputs,
        dim=1
    )
 
    prediction_tensor = torch.argmax(
        probabilities,
        dim=1
    )
 
prediction = (
    prediction_tensor
    .squeeze(0)
    .cpu()
    .numpy()
    .astype(np.uint8)
)
 
probability_map = (
    probabilities
    .squeeze(0)
    .cpu()
    .numpy()
)
 
inference_seconds = time.perf_counter() - inference_started_at
print(f"TIMING inference_seconds={inference_seconds:.3f}")

print("Inference completed.")
 
print("Prediction shape:")
print(prediction.shape)
 
print("Classes detected:")
print(np.unique(prediction))
 
 
# =====================================================
# PIXEL MASKS
# =====================================================
 
background_mask = (
    prediction == BACKGROUND_CLASS_ID
)
 
weed_mask = (
    prediction == WEED_CLASS_ID
)
 
trunk_mask = (
    prediction == TRUNK_CLASS_ID
)
 
 
# =====================================================
# COVERAGE CALCULATION
# =====================================================
 
print("\nCalculating class coverage...")
 
total_pixels = int(
    prediction.size
)
 
background_pixels = int(
    np.sum(background_mask)
)
 
weed_pixels = int(
    np.sum(weed_mask)
)
 
trunk_pixels = int(
    np.sum(trunk_mask)
)
 
background_coverage = (
    background_pixels / total_pixels
) * 100
 
weed_coverage = (
    weed_pixels / total_pixels
) * 100
 
trunk_coverage = (
    trunk_pixels / total_pixels
) * 100
 
print(
    f"Background Coverage: "
    f"{background_coverage:.2f}%"
)
 
print(
    f"Ground Weed Coverage: "
    f"{weed_coverage:.2f}%"
)
 
print(
    f"Palm Oil Tree Trunk Coverage: "
    f"{trunk_coverage:.2f}%"
)
 
 
# =====================================================
# SEVERITY
# IMPORTANT:
# Severity uses GROUND WEED coverage only.
# Trunk coverage is never added to weed coverage.
# =====================================================
 
if weed_coverage < 5:
    severity = "LOW"
else:
    severity = "HIGH"
 
print("\nSeverity:")
print(severity)
 
 
# =====================================================
# AVERAGE WEED CONFIDENCE
# =====================================================
 
if weed_pixels > 0:
    weed_probability_values = (
        probability_map[
            WEED_CLASS_ID
        ][weed_mask]
    )
 
    confidence_score = float(
        np.mean(
            weed_probability_values
        ) * 100
    )
 
else:
    confidence_score = 0.0
 
print("\nAverage Weed Confidence:")
print(f"{confidence_score:.2f}%")
 
 
# =====================================================
# GENERATE FULL THREE-CLASS SEGMENTATION MASK
#
# Background = Black
# Ground Weeds = Green
# Palm Oil Tree Trunk = Brown
# =====================================================
 
print(
    "\nGenerating three-class segmentation mask..."
)
 
colour_mask_rgb = np.zeros(
    (IMG_SIZE, IMG_SIZE, 3),
    dtype=np.uint8
)
 
colour_mask_rgb[
    background_mask
] = BACKGROUND_COLOUR
 
colour_mask_rgb[
    weed_mask
] = WEED_COLOUR
 
colour_mask_rgb[
    trunk_mask
] = TRUNK_COLOUR
 
print(
    "Three-class segmentation mask generated."
)
 
 
# =====================================================
# RESIZE PREDICTION AND MASK TO ORIGINAL IMAGE SIZE
# =====================================================
 
prediction_original = cv2.resize(
    prediction,
    (original_width, original_height),
    interpolation=cv2.INTER_NEAREST
)
 
colour_mask_original_rgb = cv2.resize(
    colour_mask_rgb,
    (original_width, original_height),
    interpolation=cv2.INTER_NEAREST
)
 
weed_mask_original = (
    prediction_original == WEED_CLASS_ID
)
 
trunk_mask_original = (
    prediction_original == TRUNK_CLASS_ID
)
 
foreground_mask_original = (
    weed_mask_original |
    trunk_mask_original
)
 
 
# =====================================================
# CREATE THREE-CLASS OVERLAY
#
# The original background remains unchanged.
# Green is applied only to weeds.
# Brown is applied only to the palm trunk.
# =====================================================
 
print(
    "\nCreating three-class overlay image..."
)
 
original_rgb = cv2.cvtColor(
    original_bgr,
    cv2.COLOR_BGR2RGB
)
 
blended_rgb = cv2.addWeighted(
    original_rgb,
    0.65,
    colour_mask_original_rgb,
    0.35,
    0
)
 
overlay_rgb = original_rgb.copy()
 
overlay_rgb[
    foreground_mask_original
] = blended_rgb[
    foreground_mask_original
]
 
print("Three-class overlay created.")
 
 
# =====================================================
# SAVE RESULTS
# =====================================================
 
os.makedirs(
    PROCESSED_FOLDER,
    exist_ok=True
)
 
filename = os.path.basename(
    image_path
)
 
name = os.path.splitext(
    filename
)[0]
 
overlay_filename = (
    f"{name}_overlay.png"
)
 
mask_filename = (
    f"{name}_mask.png"
)
 
overlay_absolute_path = os.path.join(
    PROCESSED_FOLDER,
    overlay_filename
)
 
mask_absolute_path = os.path.join(
    PROCESSED_FOLDER,
    mask_filename
)
 
overlay_saved = cv2.imwrite(
    overlay_absolute_path,
    cv2.cvtColor(
        overlay_rgb,
        cv2.COLOR_RGB2BGR
    )
)
 
mask_saved = cv2.imwrite(
    mask_absolute_path,
    cv2.cvtColor(
        colour_mask_original_rgb,
        cv2.COLOR_RGB2BGR
    )
)
 
if not overlay_saved:
    raise IOError(
        f"Failed to save overlay:\n"
        f"{overlay_absolute_path}"
    )
 
if not mask_saved:
    raise IOError(
        f"Failed to save mask:\n"
        f"{mask_absolute_path}"
    )
 
print("\nFiles saved successfully!")
 
print("Overlay:")
print(overlay_absolute_path)
 
print("Mask:")
print(mask_absolute_path)
 
 
# =====================================================
# ZONE DETECTION FROM ORIGINAL FILENAME
#
# Examples:
# A15.jpg -> Zone A
# E47_20260801_abc.jpg -> Zone E
# =====================================================
 
filename_upper = filename.upper()
 
if filename_upper.startswith("A"):
    zone = "Zone A"
 
elif filename_upper.startswith("B"):
    zone = "Zone B"
 
elif filename_upper.startswith("C"):
    zone = "Zone C"
 
elif filename_upper.startswith("D"):
    zone = "Zone D"
 
elif filename_upper.startswith("E"):
    zone = "Zone E"
 
else:
    zone = "Unknown"
 
print("\nDetected Zone:")
print(zone)
 
 
# =====================================================
# JSON OUTPUT FOR PHP
#
# Paths are web-relative so PHP and the browser can use them.
# =====================================================
 
result = {
    "status": "success",
 
    "zone": zone,
 
    "model_used": "DeepLabV3+",
 
    "processed_image_path": (
        "processed/" +
        overlay_filename
    ),
 
    "mask_path": (
        "processed/" +
        mask_filename
    ),
 
    "weed_coverage": round(
        float(weed_coverage),
        2
    ),
 
    "weed_pixels": weed_pixels,
 
    "total_pixels": total_pixels,
 
    "severity_level": severity,
 
    "confidence_score": round(
        float(confidence_score),
        2
    ),
 
    # Additional class information for technical review.
    # PHP may ignore these fields if they are not stored.
    "background_coverage": round(
        float(background_coverage),
        2
    ),
 
    "trunk_coverage": round(
        float(trunk_coverage),
        2
    ),
 
    "background_pixels": background_pixels,
 
    "trunk_pixels": trunk_pixels
}

print()
print(json.dumps(result))