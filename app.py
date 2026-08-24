import os
import cv2
import json
import numpy as np
import mysql.connector
from flask import Flask, request, jsonify
import insightface
from insightface.app import FaceAnalysis

app = Flask(__name__)

# Initialize InsightFace with ArcFace (512-dimensional output)
face_app = FaceAnalysis(name='buffalo_l', providers=['CPUExecutionProvider'])
face_app.prepare(ctx_id=0, det_size=(640, 640))

# Global memory cache storing precomputed 512-d embeddings per registered person
FACE_DATABASE = {}

def get_db_connection():
    """Connects to the MySQL database."""
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="delivery_tracking_db"
    )

def apply_clahe(img_bgr):
    """Step 2: Smooths out gate lighting, outdoor shadows, and high contrast."""
    lab = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    cl = clahe.apply(l)
    enhanced_lab = cv2.merge((cl, a, b))
    return cv2.cvtColor(enhanced_lab, cv2.COLOR_LAB2BGR)

def extract_512d_embedding(img_path):
    """Steps 2 & 3: Normalizes, aligns, and extracts deep 512-d ArcFace embedding."""
    if not os.path.exists(img_path):
        return None
    
    img = cv2.imread(img_path)
    if img is None:
        return None
    
    # Preprocess lighting
    img = apply_clahe(img)
    
    # ArcFace automatic 5-point alignment & detection
    faces = face_app.get(img)
    if len(faces) == 0:
        return None
    
    # Return 512-d embedding of the primary face
    return faces[0].embedding

def reload_face_database():
    """Re-syncs database profiles into vector memory on startup or after retrain trigger."""
    global FACE_DATABASE
    FACE_DATABASE = {}
    
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT id, full_name, role_type, face_template_path FROM frequent_personnel")
        rows = cursor.fetchall()
        
        for row in rows:
            person_id = row['id']
            full_name = row['full_name']
            paths_json = row['face_template_path']
            
            if not paths_json:
                continue
                
            try:
                path_list = json.loads(paths_json)
                if not isinstance(path_list, list):
                    path_list = [path_list]
            except Exception:
                path_list = [paths_json]
                
            embeddings = []
            for rel_path in path_list:
                full_path = os.path.join("..", rel_path) # Absolute path from python script execution context
                emb = extract_512d_embedding(full_path)
                if emb is not None:
                    embeddings.append(emb)
            
            if embeddings:
                FACE_DATABASE[person_id] = {
                    "id": person_id,
                    "name": full_name,
                    "role": row['role_type'],
                    "embeddings": embeddings # Up to 4 vectors
                }
                
        cursor.close()
        conn.close()
        print(f"[ENGINE READY] Successfully indexed {len(FACE_DATABASE)} personnel identity profiles.")
    except Exception as e:
        print(f"[ENGINE ERROR] Database sync failed: {str(e)}")

# Initialize cache on startup
reload_face_database()

@app.route('/api/retrain', methods=['POST'])
def retrain_endpoint():
    """Triggered by PHP when personnel profiles are enrolled or deleted."""
    reload_face_database()
    return jsonify({"success": True, "message": "Vector cache re-indexed successfully."})

@app.route('/api/verify', methods=['POST'])
def verify_endpoint():
    """
    Step 4: Live gate verification endpoint.
    Expects JSON: {"image_path": "../uploads/temp_live.jpg"}
    """
    data = request.json
    live_img_path = data.get('image_path')
    
    if not live_img_path or not os.path.exists(live_img_path):
        return jsonify({"success": False, "message": "Invalid frame payload."}), 400

    live_emb = extract_512d_embedding(live_img_path)
    if live_emb is None:
        return jsonify({"success": False, "message": "No face detected in video stream."}), 400

    best_match = None
    highest_similarity = -1.0
    
    # Step 4: Calibrated Cosine Similarity Threshold (0.45 for ArcFace)
    COSINE_THRESHOLD = 0.45

    for person_id, profile in FACE_DATABASE.items():
        # Evaluate live frame against all 4 registered angle vectors
        for stored_emb in profile['embeddings']:
            similarity = np.dot(live_emb, stored_emb) / (np.linalg.norm(live_emb) * np.linalg.norm(stored_emb))
            if similarity > highest_similarity:
                highest_similarity = similarity
                best_match = profile

    if highest_similarity >= COSINE_THRESHOLD:
        return jsonify({
            "success": True,
            "matched_personnel": best_match['name'],
            "personnel_id": best_match['id'],
            "role": best_match['role'],
            "confidence_score": round(float(highest_similarity) * 100, 2)
        })
    else:
        return jsonify({
            "success": False,
            "message": "Unrecognized identity.",
            "highest_score": round(float(highest_similarity) * 100, 2)
        }), 401

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5000, debug=True)