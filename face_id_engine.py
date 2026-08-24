import cv2
import mysql.connector
import numpy as np
import os
import base64
import face_recognition
from flask import Flask, request, jsonify
from flask_cors import CORS

app = Flask(__name__)
CORS(app)

KNOWN_ENCODINGS = []
KNOWN_METADATA = []
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

def process_and_add_encoding(path, metadata_row):
    """Loads image from disk, extracts 128-d embedding vector, and appends to memory array."""
    global KNOWN_ENCODINGS, KNOWN_METADATA
    if not os.path.exists(path) or path.lower().endswith('.webm'):
        return False

    try:
        # Load image via face_recognition (RGB format)
        image = face_recognition.load_image_file(path)
        encodings = face_recognition.face_encodings(image)
        
        if len(encodings) > 0:
            KNOWN_ENCODINGS.append(encodings[0])
            KNOWN_METADATA.append(metadata_row)
            return True
        return False
    except Exception as e:
        print(f"⚠️ Error encoding file {path}: {e}")
        return False

# ================= 2. ENCODING ENGINE =================
def train_face_engine():
    global KNOWN_ENCODINGS, KNOWN_METADATA, IS_TRAINED
    db = connect_db()
    if not db: 
        print("❌ Database connection dropped. Training aborted.")
        return False
    
    cursor = db.cursor(dictionary=True)
    KNOWN_ENCODINGS = []
    KNOWN_METADATA = []
    
    print("🔄 Generating deep learning face encodings...")
    base_dir = "C:/xampp/htdocs/resicured/"

    # --- 1. Index subdivision residents ---
    try:
        cursor.execute("SELECT id, user_id, full_name, face_template_path, 'Resident' as role_type, registered_vehicle_plate FROM residents WHERE face_template_path IS NOT NULL AND face_template_path != ''")
        for row in cursor.fetchall():
            primary_path = os.path.normpath(os.path.join(base_dir, row['face_template_path']))
            res_id = str(row['id'])
            user_id = str(row['user_id']) if row.get('user_id') else res_id
            
            matching_files = set()
            
            # Prioritize exact path stored in database column
            if os.path.exists(primary_path):
                matching_files.add(primary_path)

            upload_dir = os.path.dirname(primary_path) if os.path.exists(primary_path) else os.path.join(base_dir, "uploads", "faces")
            
            # Strictly match supplementary files starting with user_id or res_id prefix
            if os.path.exists(upload_dir):
                for file_name in os.listdir(upload_dir):
                    if file_name.startswith(f"face_res_{user_id}_") or file_name.startswith(f"face_res_{res_id}_"):
                        matching_files.add(os.path.join(upload_dir, file_name))

            samples = 0
            for img_file in matching_files:
                if process_and_add_encoding(img_file, row):
                    samples += 1
                    
            if samples > 0:
                print(f"  └── Encoded Resident ({samples} vectors): {row['full_name']} [user_id: {user_id}]")
            else:
                print(f"⚠️ No valid encodings extracted for Resident {row['full_name']}")
    except Exception as e:
        print(f"❌ Resident error: {e}")

    # --- 2. Index frequent personnel ---
    try:
        cursor.execute("SELECT id, full_name, face_template_path, role_type, registered_vehicle_plate FROM frequent_personnel WHERE face_template_path IS NOT NULL AND face_template_path != ''")
        for row in cursor.fetchall():
            primary_path = os.path.normpath(os.path.join(base_dir, row['face_template_path']))
            p_id = str(row['id'])
            
            matching_files = set()
            if os.path.exists(primary_path):
                matching_files.add(primary_path)

            upload_dir = os.path.dirname(primary_path) if os.path.exists(primary_path) else os.path.join(base_dir, "uploads", "frequent_personnel")
            if os.path.exists(upload_dir):
                for file_name in os.listdir(upload_dir):
                    if file_name.startswith(f"face_fp_{p_id}_") or file_name.startswith(f"personnel_{p_id}_"):
                        matching_files.add(os.path.join(upload_dir, file_name))

            samples = 0
            for img_file in matching_files:
                if process_and_add_encoding(img_file, row):
                    samples += 1
                    
            if samples > 0:
                print(f"  └── Encoded Personnel ({samples} vectors): {row['full_name']}")
    except Exception as e:
        print(f"❌ Personnel error: {e}")

    # --- 3. Index staff guards ---
    try:
        cursor.execute("SELECT id, username AS full_name, image AS face_template_path, 'Guard' as role_type, NULL as registered_vehicle_plate FROM users WHERE role = 'guard' AND image IS NOT NULL AND image != '' AND image != 'default_guard.png'")
        for row in cursor.fetchall():
            img_path_raw = row['face_template_path']
            p1 = os.path.normpath(os.path.join(base_dir, img_path_raw))
            p2 = os.path.normpath(os.path.join(base_dir, "uploads", "guards", img_path_raw))
            primary_path = p1 if os.path.exists(p1) else p2

            if process_and_add_encoding(primary_path, row):
                print(f"  └── Encoded Guard: {row['full_name']}")
    except Exception as e:
        print(f"❌ Guard error: {e}")

    cursor.close()
    db.close()

    if len(KNOWN_ENCODINGS) > 0:
        IS_TRAINED = True
        print(f"✅ Engine ready. Total active facial encodings: {len(KNOWN_ENCODINGS)}")
        return True
    else:
        print("⚠️ No valid reference encodings found.")
        IS_TRAINED = False
        return False

# Trigger initial engine vector training on server boot
train_face_engine()

# ================= 3. API ROUTES =================

@app.route('/status', methods=['GET'])
def system_status_check():
    return jsonify({"status": "online", "profiles_loaded": len(KNOWN_ENCODINGS), "engine_trained": IS_TRAINED})

@app.route('/api/retrain', methods=['POST'])
def force_engine_retrain():
    success = train_face_engine()
    return jsonify({"status": "success", "engine_trained": success})

@app.route('/api/scan', methods=['POST'])
@app.route('/api/process-face', methods=['POST'])
def process_biometric_scan():
    try:
        data = request.get_json(force=True, silent=True) or {}
        
        # Auto-retrain request triggered by registration action
        if 'image_paths' in data or 'user_id' in data:
            success = train_face_engine()
            return jsonify({"status": "success", "message": "Face engine retrained successfully.", "engine_trained": success})

        image_raw = (
            data.get('image') or 
            data.get('face') or 
            data.get('frame') or 
            data.get('photo') or
            request.form.get('image') or
            request.form.get('face')
        )
        
        if not image_raw:
            return jsonify({"status": "error", "message": "Missing image property payload."}), 400

        encoded = image_raw.split(",", 1)[1] if "," in image_raw else image_raw
        image_bytes = base64.b64decode(encoded)
        
        nparr = np.frombuffer(image_bytes, np.uint8)
        frame = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if frame is None:
            return jsonify({"status": "error", "message": "Failed to decode frame bytes."}), 400

        # Convert OpenCV BGR frame to RGB for face_recognition
        rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        
        # Detect faces in live stream frame
        scan_locations = face_recognition.face_locations(rgb_frame)
        scan_encodings = face_recognition.face_encodings(rgb_frame, scan_locations)

        if not scan_encodings:
            return jsonify({"status": "denied", "message": "Biometric Signature Missing: No clear face detected."})

        if not IS_TRAINED or not KNOWN_ENCODINGS:
            return jsonify({"status": "denied", "message": "Access Denied: Classification engine not ready."})

        live_encoding = scan_encodings[0]

        # Calculate Euclidean distance between live encoding vector and memory vector database
        distances = face_recognition.face_distance(KNOWN_ENCODINGS, live_encoding)
        best_match_idx = np.argmin(distances)
        dist_score = round(float(distances[best_match_idx]), 3)

        # Distance threshold metrics (Lower = stricter match)
        STRICT_MATCH = 0.42
        MAX_TOLERANCE = 0.48

        match = dict(KNOWN_METADATA[best_match_idx])
        predicted_name = match['full_name']

        if dist_score < STRICT_MATCH:
            print(f"🔓 ACCESS AUTHORIZED (Strong Match): {predicted_name} | Vector Distance: {dist_score}")
            match['match_confidence'] = "High"
            return jsonify({"status": "verified", "data": match})
            
        elif dist_score <= MAX_TOLERANCE:
            print(f"🔓 ACCESS AUTHORIZED (Moderate Match): {predicted_name} | Vector Distance: {dist_score}")
            match['match_confidence'] = "Moderate"
            return jsonify({"status": "verified", "data": match})
            
        else:
            print(f"🔒 ACCESS DENIED: Best candidate {predicted_name} score ({dist_score}) exceeded threshold {MAX_TOLERANCE}.")
            return jsonify({"status": "denied", "message": "Access Denied: Identity match confidence too low."})

    except Exception as e:
        print(f"❌ Server Error: {str(e)}")
        return jsonify({"status": "error", "message": str(e)}), 500

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=False)