# CAD 도면 편집 (DWG / DXF)

## 사용

공정별 도면에서 **CAD 도면 편집**을 열거나 `/cad?site_id=현장ID`로 들어간다. 현장을 고른 뒤 DWG 또는 UTF-8 ASCII DXF(최대 64MiB)를 올린다. 업로드 옆에는 현재 서버의 실제 파일 한도가 표시되며, PHP 설정이 낮으면 그 한도가 우선한다. 원본은 비공개로 보관하며 편집 저장은 새 DXF 개정을 만든다. **DWG 내려받기**는 선택한 개정을 실제 DWG 2018(AC1032) 파일로 변환한다. ERP에 저장하는 작업과 DWG 파일 내려받기는 별도 동작이다.

편집: 직선, 연속선, 사각형, 원, 원호, 문자 상자, 위치를 조정할 수 있는 거리 치수, 거리 측정; 선택, 이동, 복사, 회전, 균일 배율, 대칭, 삭제, 직선/원 오프셋, 두 직선 자르기/연장, 직사각 배열. 좌표 입력, 스냅, 직교, 격자, 레이어, 색/선종류/선굵기, 실행 취소/다시 실행, 화면 이동/확대/전체 보기를 제공한다. 작업 전에 선택한 도구의 안내를 확인한다. 거리 수치는 파일의 도면 단위이며 자동 단위 추정이나 법정 물량 확정에 쓰지 않는다.

다른 사용자가 먼저 저장하면 409로 거절하고 현재 브라우저 편집을 유지한다. DXF를 내려받아 보관하고 최신 개정을 연다. 변경 이력에서 이전 개정을 읽거나 내려받을 수 있다.

불러오기 진행 상태와 실패 이유는 도면 영역 중앙에 표시한다. 실패해도 기존 도면과 저장하지 않은 수정은 유지되며 같은 파일을 다시 선택할 수 있다. 성공한 업로드는 도면 목록 갱신에 실패하더라도 바로 열린다.

## 거리·치수와 문자 입력

끝점·중점·중심 스냅을 켜고 거리 측정 또는 거리 치수를 선택한다. 꼭지점 가까이 마우스를 옮기면 스냅 표시가 나타난다. 첫 점과 두 번째 점을 선택하고, 마우스를 위·아래·좌·우로 움직여 치수선을 놓을 위치를 정한 뒤 한 번 더 클릭한다. 정렬 모드는 두 점 사이 실제 거리, 수평·수직 모드는 해당 축에 투영한 거리를 표시한다. 측정 중 수치와 선이 미리 보이며 Esc로 취소한다.

거리 측정은 화면의 보조 표시이며 도면 저장이나 기성 수량을 변경하지 않는다. 거리 치수는 도면에 남는 주석이다. 선택 후 치수선과 숫자의 손잡이를 드래그하여 위치를 조정하고 저장한다. 치수의 기준점은 작성 당시의 좌표다. 기준 도형을 나중에 수정하면 자동으로 따라가지 않으므로 다시 측정해야 한다.

문자는 도구를 고른 뒤 캔버스에 클릭하거나 상자 폭을 드래그하여 지정한다. 입력창에 여러 줄을 입력하고 크기·색·폭을 정한 뒤 적용한다. 빈 내용이나 취소는 객체를 생성하지 않는다. 기존 편집 가능한 문자는 선택 후 입력창을 열거나 더블클릭하여 수정한다. 입력 중 Enter는 줄바꿈, Ctrl+Enter는 적용, Esc는 취소다. 복잡한 서식이나 3D 속성을 가진 원래 도면의 문자는 원본 보존을 위해 읽기 전용일 수 있다.

새 치수는 표준 LINE/TEXT 객체와 전용 XDATA 정보를 함께 저장한다. 다른 CAD에서도 선과 숫자를 볼 수 있으며, 나손에서 다시 열 때 완전하고 기하가 일치하는 묶음만 편집 가능한 치수로 복원한다. 외부 CAD에서 일부를 수정하거나 삭제한 묶음은 기존 선·문자로 유지한다. AutoCAD의 연관 DIMENSION 객체를 새로 만드는 기능은 아니다. 새 문자 상자는 일반 MTEXT로 저장하며 복잡한 서식을 임의로 단순화하지 않는다.

MTEXT 방향은 표준 11/21/31 방향 벡터로 기록한다. [Autodesk MTEXT DXF 명세](https://help.autodesk.com/cloudhelp/2020/ENU/AutoCAD-DXF/files/GUID-5E5DB93B-F8D3-4433-ADF7-E92E250D2BAB.htm)의 code 50 라디안 입력과 ACadSharp의 일반 각도 판독 간 차이를 피하고, 회전된 문자도 실제 DWG 왕복에서 보존한다.

## 기성 편집에서 사용

기성 줄의 **기성 편집** 또는 **도면 · 자료** 화면에서 **CAD 편집 · 기성 증빙 연결**을 연다. 먼저 현재 기성 초안을 저장한 뒤 같은 화면 안에서 CAD 편집기를 연다. 대상 계약·기성 줄·현장이 표시되며 해당 현장의 DWG/DXF를 올리거나 기존 CAD 도면을 선택할 수 있다.

CAD 편집을 저장한 뒤 **기성 증빙에 연결**을 누르면 저장한 정확한 도면 개정이 현재 사용자의 기성 초안에 연결된다. CAD 창을 닫으면 부모 편집기가 저장된 초안과 증빙 목록을 다시 읽는다. CAD를 여는 동안 부모의 수정은 잠겨 두 창의 초안이 서로 덮어쓰지 않도록 한다. 다른 사용자나 창이 먼저 초안을 저장했다면 연결을 거절하고 최신 상태를 확인하도록 안내한다.

연결한 증빙은 도면의 최신 버전을 따라 바뀌지 않는다. 예를 들어 개정 2를 연결한 뒤 도면을 개정 3으로 수정해도 기존 증빙은 개정 2를 유지한다. 증빙 보기에서 그 개정을 읽기 전용으로 열고, 기성 증빙 묶음에는 해당 DXF 원본과 해시가 포함된다. 최신 도면을 증빙으로 쓰려면 새 개정을 명시적으로 연결한다.

이 연결은 초안의 자료를 추가하는 작업이다. 도면의 거리나 면적을 기성 수량으로 자동 확정하지 않는다. 실제 작업 기록·확인·회차 포함은 기존 기성 절차를 사용한다. 이미 제출한 작업 기록은 도면 연결로 바뀌지 않으며 추가 자료는 새 초안에서 작업한다.

## 지원 범위

- 모델 공간의 지원하는 2D 객체를 편집한다. 일부 클래식 폴리선·타원·블록은 미리보기만 제공한다. 3D, 종이 공간, 곡선 폴리선, 특수 객체는 편집 대상이 아니다. 화면에 표시하지 못하는 객체 개수를 알린다.
- 수정하지 않은 DXF 객체와 다른 섹션의 그룹 코드/값을 유지한다. 줄바꿈은 CRLF로 정규화하므로 DXF 파일 전체가 바이트 단위로 같다는 뜻은 아니다. 업로드 원본은 별도 파일로 그대로 보관한다.
- DWG↔DXF 변환은 ACadSharp 3.8.0으로 수행한다. 이름과 RGB 값이 모두 같은 중복 별색 정의만 합치며, 같은 이름에 서로 다른 RGB 값이 있으면 거절한다. 라이브러리의 이름/사전 키 처리 차이를 검증하는 호환 처리는 3.8.0에 고정되며 내부 구조가 달라지면 거절한다.
- 2D 작업본은 3D 표시 스타일·태양광·연관 자동 갱신·상세/단면 보기 스타일과 해당 정보만 소유한 부가 레코드를 제외할 수 있고, 제외 사실을 경고에 표시한다. 일반 XRecord, 블록별 도형 종류·개수, 표시 RGB는 왕복 보존 검사를 통과해야 한다. 그 밖의 알 수 없는 프록시 객체, 동적 블록 정보, 읽기 오류, 미구현 객체는 계속 거절한다. 사용자 정의 객체·폰트·외부 참조·레이아웃의 완전한 보존을 보장하지 않으므로 내보낸 파일은 원래 사용하던 CAD에서 확인한다. 원본 DWG는 별도 보존한다.
- 설치된 LibreCAD v2.2.1.5의 실행 파일은 서버 변환 엔진이 아니다. REA 분석 범위와 근거는 `CAD_REA_ANALYSIS.md`에 기록했다. 웹 편집 코드는 별도로 작성했으며 GPL 소스를 복사하거나 바이너리를 포함하지 않았다.

## 서버 설치

PHP 앱과 같은 서버에서 .NET 8 런타임 및 아래 빌드를 사용하거나 Linux x64 자체 포함 실행 파일을 배포한다. 외부 변환 사이트로 도면을 보내지 않는다.

```sh
dotnet restore services/cad-converter/CadConverter.csproj --locked-mode
dotnet publish services/cad-converter/CadConverter.csproj -c Release -r linux-x64 --self-contained true -o /opt/nahshon-cad
/opt/nahshon-cad/Nahshon.CadConverter capabilities
php artisan migrate --force
```

환경변수:

```dotenv
CAD_CONVERTER_BINARY=/opt/nahshon-cad/Nahshon.CadConverter
CAD_STORAGE_DISK=s3
```

프레임워크 종속 DLL 배포는 `CAD_CONVERTER_BINARY=/absolute/Nahshon.CadConverter.dll`, `CAD_DOTNET_BINARY=/absolute/dotnet`을 설정한다. 앱 서비스 계정이 엔진을 실행하고 `storage/app/private/cad-tmp`를 생성할 수 있어야 한다. 엔진 설정이 없거나 실행되지 않으면 DWG 기능은 비활성화되고 DXF 편집을 사용할 수 있다. 실행 파일 존재만으로 지원 가능 표시를 하지 않고 `capabilities` 명령 응답을 확인한다.

운영 파일은 비공개 영구 디스크가 필요하다. `CAD_STORAGE_DISK`가 비어 있으면 문서함의 `document-intelligence.disk`를 사용한다. 공개 디스크나 운영 임시 로컬 디스크에는 업로드를 허용하지 않는다. 스토리지 읽기/쓰기는 권한 검증 후 서버에서 수행한다. 원본·변환 입력/출력·편집 DXF 한도는 `config/cad.php`의 `max_bytes` 한 곳을 사용하며 PHP가 변환기 프로세스에 전달한다. DWG가 DXF로 풀리면서 64MiB를 넘으면 변환을 거절한다. 서버는 한도를 검사한 뒤 DXF 코드 쌍을 순차 검사해 거대한 줄 배열을 만들지 않는다.

`public/.user.ini`는 파일 64M, 요청 전체 144M로 설정한다. 요청 한도는 DXF 줄바꿈 등의 JSON 이스케이프와 표시 데이터를 수용하기 위해 파일 한도보다 크다. 프록시 한도도 이에 맞춰야 하며 `/build-version`에서 실제 PHP 적용값을 확인한다. `/admin-api/cad/capabilities`의 `limits`는 유효 업로드·편집 DXF·요청 본문 한도를 제공한다. 초과 파일은 PDF 처리/업로드 전에 한국어로 안내하고, 초과 저장 요청은 현재 미저장 편집을 유지한다. 파일 크기가 한도 안이어도 지원되지 않는 객체나 변환 시간 제한 때문에 실패할 수 있다.

루트 Dockerfile에는 Linux 변환기 빌드와 `/opt/cad` 설치가 포함되어 있다. Laravel Cloud에서는 기존 Build commands 끝에 `bash scripts/build-cad-converter.sh`를 추가한다. 이 스크립트는 .NET SDK가 없으면 Microsoft 공식 고정 버전 아카이브를 SHA512로 검증해 임시 폴더에 설치하고, 자체 포함 Linux 실행 파일을 `services/cad-converter/publish`에 빌드한다. PHP 설정은 이 경로를 기본으로 찾으므로 별도 비밀값 수정이 필요 없다. `capabilities` 실행이 실패하면 빌드도 실패한다. 코드 병합만으로 기존 Laravel Cloud의 DWG 엔진이 준비되었다고 간주하지 않는다. 파일 다운로드·변환은 20초 HTTP 제한을 넘길 수 있으므로 현장 도면으로 확인 후 서버 timeout과 PHP 한도를 조정한다.

ACadSharp의 MIT 고지는 `services/cad-converter/ACadSharp.LICENSE`에 포함했다. 상용 CAD 라이선스를 재사용하지 않는다.

## 검증

```sh
node --test tests/js/cad-core.test.mjs
dotnet build services/cad-converter/CadConverter.csproj -c Release -p:RestoreLockedMode=true
node scripts/test-cad-roundtrip.mjs /absolute/dotnet /absolute/Nahshon.CadConverter.dll /new/scratch/directory
dotnet run --project tests/cad-converter/CadCompatibilityTests.csproj -c Release -- /absolute/dotnet /absolute/Nahshon.CadConverter.dll
CAD_TEST_CONVERTER_BINARY=/absolute/Nahshon.CadConverter.dll CAD_TEST_DOTNET_BINARY=/absolute/dotnet php vendor/bin/phpunit --filter CadDrawingTest
```

왕복 테스트는 웹 편집기 직렬화 → 실제 DWG → DXF 재열기 → 좌표 이동 → DWG 재저장 → DXF 재열기를 검사하며 한글 문자와 원 반지름도 확인한다. 기존 출력 파일을 덮어쓰지 않으므로 재실행 시 새 scratch 디렉터리를 사용한다. PHP 테스트는 별도 `nahshon_smart_erp_test` 데이터베이스에서만 실행한다. `.github/workflows/cad.yml`은 Linux에서 실제 변환과 권한/이력 API 테스트를 함께 실행한다. 실제 사용자 DWG의 수용 검사는 추가로 필요하다.

2026-10-20 로컬 검증 당시 Windows Application Control(0x800711C7)이 새로 빌드한 변환기 DLL 실행을 차단했다. 빌드 성공과 실제 DWG 왕복 성공을 구분하며, 정책을 우회하지 않았다. Linux CI 결과가 통과하기 전에는 DWG 저장 검증 완료로 표시하지 않는다.
