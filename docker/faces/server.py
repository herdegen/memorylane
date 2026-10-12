"""Service HTTP minimal autour d'InsightFace (buffalo_l).

  GET  /health                         → {"status": "ok"}
  POST /analyze   (corps : image)      → visages détectés : boîte, score,
                                          5 points, empreinte 512 normée, âge
  POST /embed?x1=&y1=&x2=&y2=[&pct=1] → empreinte d'UNE zone dessinée à la
                                          main (détection dans la zone, sinon
                                          recadrage direct)
  POST /embed-many?boxes=[…]&pct=1&full=0 → idem pour plusieurs zones

Images décodées avec Pillow en appliquant l'orientation EXIF (comme
l'affichage), coordonnées en pixels de l'image ainsi orientée.
"""
import io
import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

import cv2
import numpy as np
from insightface.app import FaceAnalysis
from PIL import Image, ImageOps

MAX_BYTES = 60 * 1024 * 1024
app = FaceAnalysis(name='buffalo_l', root='/models', providers=['CPUExecutionProvider'],
                   allowed_modules=['detection', 'recognition', 'genderage'])
app.prepare(ctx_id=-1, det_size=(1024, 1024))
lock = threading.Lock()  # une analyse à la fois : le CPU est partagé avec l'appli


def decode(data: bytes):
    img = ImageOps.exif_transpose(Image.open(io.BytesIO(data))).convert('RGB')
    return cv2.cvtColor(np.asarray(img), cv2.COLOR_RGB2BGR)


def face_json(f):
    return {
        'box': [round(float(v), 1) for v in f.bbox],
        'score': round(float(f.det_score), 4),
        'kps': [[round(float(x), 1), round(float(y), 1)] for x, y in f.kps],
        'embedding': [round(float(v), 6) for v in f.normed_embedding],
        'age': int(f.age) if getattr(f, 'age', None) is not None else None,
        'sex': getattr(f, 'sex', None),
    }


def iou(a, b):
    ix = max(0.0, min(a[2], b[2]) - max(a[0], b[0]))
    iy = max(0.0, min(a[3], b[3]) - max(a[1], b[1]))
    inter = ix * iy
    union = (a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter
    return inter / union if union > 0 else 0.0


def overlaps(box, det):
    """Même visage : bon recouvrement, ou centre de la détection dans la zone
    (les anciens cadres, cheveux compris, sont souvent plus grands)."""
    v = iou(box, det)
    cx, cy = (det[0] + det[2]) / 2, (det[1] + det[3]) / 2
    inside = box[0] <= cx <= box[2] and box[1] <= cy <= box[3]
    return v >= 0.3 or (inside and v >= 0.05)


def embed_box(img, box, full=True):
    """Empreinte d'une zone dessinée : visage détecté qui la recouvre, sinon
    détection dans la zone élargie, sinon recadrage (112×112) passé
    directement au modèle de reconnaissance (moins fiable)."""
    h, w = img.shape[:2]
    x1, y1, x2, y2 = box
    # 1. Le visage détecté sur la photo entière qui recouvre la zone (sauté
    #    quand l'appelant sait déjà qu'aucune détection ne la recouvre).
    faces = app.get(img) if full else []
    if faces:
        best = max(faces, key=lambda f: iou(box, f.bbox))
        if overlaps(box, best.bbox):
            out = face_json(best)
            out['source'] = 'detected'
            return out
    # 2. Sinon une détection dans la zone élargie (petits visages).
    mx, my = (x2 - x1) * 0.5, (y2 - y1) * 0.5
    cx1, cy1 = int(max(0, x1 - mx)), int(max(0, y1 - my))
    cx2, cy2 = int(min(w, x2 + mx)), int(min(h, y2 + my))
    crop = img[cy1:cy2, cx1:cx2]
    if crop.size:
        faces = app.get(crop)
        if faces:
            local = [x1 - cx1, y1 - cy1, x2 - cx1, y2 - cy1]
            best = max(faces, key=lambda f: iou(local, f.bbox))
            if overlaps(local, best.bbox):
                out = face_json(best)
                out['box'] = [out['box'][0] + cx1, out['box'][1] + cy1, out['box'][2] + cx1, out['box'][3] + cy1]
                out['source'] = 'detected'
                return out
    tight = img[int(max(0, y1)):int(min(h, y2)), int(max(0, x1)):int(min(w, x2))]
    if not tight.size:
        return None
    rec = app.models['recognition']
    feat = rec.get_feat(cv2.resize(tight, (112, 112))).flatten()
    feat = feat / np.linalg.norm(feat)
    return {'box': [x1, y1, x2, y2], 'score': None, 'kps': None,
            'embedding': [round(float(v), 6) for v in feat], 'age': None, 'sex': None, 'source': 'crop'}


class Handler(BaseHTTPRequestHandler):
    def reply(self, code, payload):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if urlparse(self.path).path == '/health':
            return self.reply(200, {'status': 'ok', 'model': 'buffalo_l'})
        self.reply(404, {'error': 'not found'})

    def do_POST(self):
        url = urlparse(self.path)
        length = int(self.headers.get('Content-Length') or 0)
        if length <= 0 or length > MAX_BYTES:
            return self.reply(413, {'error': 'image absente ou trop grande'})
        try:
            img = decode(self.rfile.read(length))
        except Exception as e:  # image illisible
            return self.reply(422, {'error': f'image illisible : {e}'})
        h, w = img.shape[:2]
        try:
            with lock:
                if url.path == '/analyze':
                    faces = [face_json(f) for f in app.get(img)]
                    return self.reply(200, {'width': w, 'height': h, 'faces': faces})
                if url.path == '/embed-many':
                    # Plusieurs zones d'une même photo en un envoi : boxes=[[x1,y1,x2,y2],…]
                    q = parse_qs(url.query)
                    pct = q.get('pct', ['0'])[0] == '1'
                    out = []
                    for b in json.loads(q['boxes'][0]):
                        box = [b[0] * w / 100, b[1] * h / 100, b[2] * w / 100, b[3] * h / 100] if pct else b
                        out.append(embed_box(img, box, full=q.get('full', ['1'])[0] == '1'))
                    return self.reply(200, {'width': w, 'height': h, 'faces': out})
                if url.path == '/embed':
                    q = parse_qs(url.query)
                    box = [float(q[k][0]) for k in ('x1', 'y1', 'x2', 'y2')]
                    if q.get('pct', ['0'])[0] == '1':  # zone en % de l'image orientée
                        box = [box[0] * w / 100, box[1] * h / 100, box[2] * w / 100, box[3] * h / 100]
                    return self.reply(200, {'width': w, 'height': h, 'face': embed_box(img, box)})
        except Exception as e:
            return self.reply(500, {'error': str(e)})
        self.reply(404, {'error': 'not found'})

    def log_message(self, fmt, *args):  # journal sobre (pas de contenu d'image)
        print('%s %s' % (self.command, self.path.split('?')[0]), flush=True)


if __name__ == '__main__':
    print('Service visages prêt (buffalo_l)', flush=True)
    ThreadingHTTPServer(('0.0.0.0', 8000), Handler).serve_forever()
