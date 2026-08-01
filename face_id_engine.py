import cv2
import mysql.connector
import numpy as np
import os
import base64
import glob
from flask import Flask, request, jsonify
from flask_cors import CORS

app = Flask(__name__)
CORS(app)

# Load native face tracking map
face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')

# INITIALIZE LBPH PATTERN RECOGNIZER
recognizer = cv2.face.LBPHFaceRecognizer_create(radius=1, neighbors=8, grid_x=8, grid_y=8)

METADATA_MAP = {}
IS_TRAINED = False

# ================= 1. DATABASE CONFIGURATION =================
def connect_db():
    try:
        return mysql.connector.connect(
            host="localhost", user="root", password="", database="resicured_db"
        )
    except Exception as e:
        print(f"❌ Database error: {e}")
        return None

# Helper function to detect, crop, and normalize face before appending
def extract_and_append_face(path, label_id, face_samples, face_ids):
    if not os.path.exists(path) or path.lower().endswith('.webm'):
        return False

    img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        return False

    faces = face_cascade.detectMultiScale(img, scaleFactor=1.1, minNeighbors=4, minSize=(40, 40))
    if len(faces) > 0:
        (x, y, w, h) = faces[0]
        cropped = img[y:y+h, x:x+w]
        cropped = cv2.resize(cropped, (200, 200))
        cropped = cv2.equalizeHist(cropped)
        face_samples.append(cropped)
        face_ids.append(label_id)
        return True
    else:
        # Fallback if image is already a close-up cropped face
        img = cv2.resize(img, (200, 200))
        img = cv2.equalizeHist(img)
        face_samples.append(img)
        face_ids.append(label_id)
        return True

# ================= 2. TRAIN RECOGNIZER MATRICES =================
def train_face_engine():
    global METADATA_MAP, IS_TRAINED
    db = connect_db()
    if not db: 
        print("❌ Database connection dropped. Training aborted.")
        return False
    
    cursor = db.cursor(dictionary=True)
    face_samples = []
    face_ids = []
    METADATA_MAP = {}
    
    print("🔄 Preloading identity profile matrices and training engine...")
    internal_id_counter = 1
    base_dir = "C:/xampp/htdocs/resicured/"

    # --- 1. Index subdivision residents ---
    try:
        cursor.execute("SELECT id, full_name, face_template_path, 'Resident' as role_type, registered_vehicle_plate FROM residents WHERE face_template_path IS NOT NULL AND face_template_path != ''")
        for row in cursor.fetchall():
            current_label = internal_id_counter
            METADATA_MAP[current_label] = row
            
            primary_path = os.path.normpath(os.path.join(base_dir, row['face_template_path']))
            upload_dir = os.path.dirname(primary_path)
            res_id = row['id']
            
            # Flexible wildcard matching to load all image variations in uploads/faces/
            matching_files = (
                glob.glob(os.path.join(upload_dir, f"face_res_{res_id}_*.jpg")) +
                glob.glob(os.path.join(upload_dir, f"face_res_{res_id}_*.png")) +
                glob.glob(os.path.join(upload_dir, f"*_{res_id}_*.jpg"))
            )
            
            # Fallback if single file or comma-separated
            if not matching_files:
                raw_paths = [p.strip() for p in row['face_template_path'].split(',') if p.strip()]
                matching_files = [os.path.normpath(os.path.join(base_dir, p)) for p in raw_paths]
                
            samples_loaded = 0
            for img_file in set(matching_files):
                if extract_and_append_face(img_file, current_label, face_samples, face_ids):
                    samples_loaded += 1
            
            if samples_loaded > 0:
                print(f"  └── Loaded Resident ({samples_loaded} samples): {row['full_name']} (ID: {row['id']})")
                internal_id_counter += 1
            else:
                print(f"⚠️ No valid image files loaded for Resident {row['full_name']} (ID: {row['id']})")
    except Exception as e:
        print(f"❌ Error loading residents: {e}")
        
    # --- 2. Index frequent personnel ---
    try:
        cursor.execute("SELECT id, full_name, face_template_path, role_type, registered_vehicle_plate FROM frequent_personnel WHERE face_template_path IS NOT NULL AND face_template_path != ''")
        for row in cursor.fetchall():
            current_label = internal_id_counter
            METADATA_MAP[current_label] = row
            
            primary_path = os.path.normpath(os.path.join(base_dir, row['face_template_path']))
            upload_dir = os.path.dirname(primary_path)
            p_id = row['id']
            
            # Flexible wildcard matching for personnel images
            matching_files = (
                glob.glob(os.path.join(upload_dir, f"*_{p_id}_*.jpg")) +
                glob.glob(os.path.join(upload_dir, f"*_{p_id}_*.png")) +
                glob.glob(os.path.join(upload_dir, f"*_{p_id}.jpg"))
            )
            
            if not matching_files:
                raw_paths = [p.strip() for p in row['face_template_path'].split(',') if p.strip()]
                matching_files = [os.path.normpath(os.path.join(base_dir, p)) for p in raw_paths]

            samples_loaded = 0
            for img_file in set(matching_files):
                if extract_and_append_face(img_file, current_label, face_samples, face_ids):
                    samples_loaded += 1

            if samples_loaded > 0:
                print(f"  └── Loaded Personnel ({samples_loaded} samples): {row['full_name']} (ID: {row['id']})")
                internal_id_counter += 1
            else:
                print(f"⚠️ No valid image files loaded for Personnel {row['full_name']} (ID: {row['id']})")
    except Exception as e:
        print(f"❌ Error loading personnel: {e}")

    # --- 3. Index staff guards (from users table where role = 'guard') ---
    try:
        cursor.execute("SELECT id, username AS full_name, image AS face_template_path, 'Guard' as role_type, NULL as registered_vehicle_plate FROM users WHERE role = 'guard' AND image IS NOT NULL AND image != '' AND image != 'default_guard.png'")
        for row in cursor.fetchall():
            current_label = internal_id_counter
            METADATA_MAP[current_label] = row
            
            img_path_raw = row['face_template_path']
            
            if img_path_raw.startswith("uploads"):
                primary_path = os.path.normpath(os.path.join(base_dir, img_path_raw))
            else:
                p1 = os.path.normpath(os.path.join(base_dir, "uploads", "guards", img_path_raw))
                p2 = os.path.normpath(os.path.join(base_dir, "uploads", "faces", img_path_raw))
                primary_path = p1 if os.path.exists(p1) else p2
                
            upload_dir = os.path.dirname(primary_path)
            g_id = row['id']
            
            matching_files = (
                glob.glob(os.path.join(upload_dir, f"face_guard_{g_id}_*.jpg")) +
                glob.glob(os.path.join(upload_dir, f"face_guard_{g_id}_*.png")) +
                glob.glob(os.path.join(upload_dir, f"*_{g_id}_*.jpg")) +
                glob.glob(os.path.join(upload_dir, f"*_{g_id}.jpg"))
            )
            
            if not matching_files and os.path.exists(primary_path):
                matching_files = [primary_path]

            samples_loaded = 0
            for img_file in set(matching_files):
                if extract_and_append_face(img_file, current_label, face_samples, face_ids):
                    samples_loaded += 1

            if samples_loaded > 0:
                print(f"  └── Loaded Guard ({samples_loaded} samples): {row['full_name']} (ID: {row['id']})")
                internal_id_counter += 1
            else:
                print(f"⚠️ No valid image files loaded for Guard {row['full_name']} (ID: {row['id']})")
    except Exception as e:
        print(f"❌ Error loading guards: {e}")

    cursor.close()
    db.close()

    if len(face_samples) > 0:
        recognizer.train(face_samples, np.array(face_ids))
        IS_TRAINED = True
        print(f"✅ Model successfully trained with {len(face_samples)} identity sample matrices.")
        return True
    else:
        print("⚠️ Training skipped: No valid reference images were found on disk.")
        IS_TRAINED = False
        return False

# Trigger initial startup matrix training
train_face_engine()

# ================= 3. API ROUTES =================

@app.route('/status', methods=['GET'])
def system_status_check():
    return jsonify({"status": "online", "profiles_loaded": len(METADATA_MAP), "engine_trained": IS_TRAINED})

@app.route('/api/retrain', methods=['POST'])
def force_engine_retrain():
    success = train_face_engine()
    return jsonify({"status": "success", "engine_trained": success})

@app.route('/api/scan', methods=['POST'])
@app.route('/api/process-face', methods=['POST'])
def process_biometric_scan():
    global IS_TRAINED
    try:
        data = request.get_json(force=True, silent=True) or {}
        
        # --- HANDLER 1: Registration/Update notification from PHP ---
        if 'image_paths' in data or 'user_id' in data:
            print(f"📥 Registration payload received for User ID: {data.get('user_id')}. Retraining engine...")
            success = train_face_engine()
            return jsonify({"status": "success", "message": "Face engine retrained successfully.", "engine_trained": success})

        # --- HANDLER 2: Live Webcam Gate Scan ---
        image_raw = (
            data.get('image') or 
            data.get('face') or 
            data.get('frame') or 
            data.get('photo') or
            request.form.get('image') or
            request.form.get('face')
        )
        
        if not image_raw:
            print("⚠️ HTTP 400 Bad Request: Missing image property payload.")
            return jsonify({"status": "error", "message": "Missing image property data structure stream."}), 400
            
        if "," in image_raw:
            header, encoded = image_raw.split(",", 1)
        else:
            encoded = image_raw

        image_bytes = base64.b64decode(encoded)
        
        nparr = np.frombuffer(image_bytes, np.uint8)
        frame = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        
        if frame is None:
            return jsonify({"status": "error", "message": "Failed to decode frame bytes into OpenCV image."}), 400

        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        
        faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(50, 50))
        
        if len(faces) == 0:
            return jsonify({"status": "denied", "message": "Biometric Signature Missing: No clear face detected."})
            
        if not IS_TRAINED or not METADATA_MAP:
            return jsonify({"status": "denied", "message": "Access Denied: Facial classification engine is not trained yet."})

        (x, y, w, h) = faces[0]
        cropped_face = gray[y:y+h, x:x+w]
        cropped_face = cv2.resize(cropped_face, (200, 200))
        cropped_face = cv2.equalizeHist(cropped_face)
        
        # General Multi-Tier Distance Thresholds (Applies to ALL users)
        STRICT_MATCH = 60.0          # Tier 1: Optimal match threshold
        MAX_TOLERANCE = 75.0         # Tier 2: Acceptable webcam/lighting variance

        # LBPH Face Pattern Prediction
        label, confidence = recognizer.predict(cropped_face)
        dist_score = round(confidence, 2)
        
        if label in METADATA_MAP:
            match = METADATA_MAP[label]
            predicted_name = match['full_name']
            
            # --- Tier 1: High Confidence Match ---
            if confidence < STRICT_MATCH:
                print(f"🔓 ACCESS AUTHORIZED (Strong Match): {predicted_name} | Distance Score: {dist_score}")
                match['match_confidence'] = "High"
                return jsonify({"status": "verified", "data": match})
                
            # --- Tier 2: Acceptable Moderate Variance Match ---
            elif confidence <= MAX_TOLERANCE:
                print(f"🔓 ACCESS AUTHORIZED (Moderate Match): {predicted_name} | Distance Score: {dist_score}")
                match['match_confidence'] = "Moderate"
                return jsonify({"status": "verified", "data": match})
                
            # --- Tier 3: Exceeds Maximum Tolerance ---
            else:
                print(f"🔒 ACCESS DENIED: {predicted_name} score ({dist_score}) exceeded max tolerance of {MAX_TOLERANCE}.")
                return jsonify({"status": "denied", "message": "Access Denied: Distance score variance too high."})
        else:
            print(f"DEBUG: Unknown label index {label} | Distance score: {dist_score}")
            print("🔒 ACCESS DENIED: Identity mismatch or unindexed profile label.")
            return jsonify({"status": "denied", "message": "Access Denied: Unknown profile signature matrix detected."})
        
    except Exception as e:
        print(f"❌ Server Error: {str(e)}")
        return jsonify({"status": "error", "message": str(e)}), 500

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=False)