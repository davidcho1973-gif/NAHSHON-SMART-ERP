<?php

use App\Models\AiJob;
use App\Models\AttendanceQrCode;
use App\Models\ClaimWorkRecord;
use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\ContractBoqLine;
use App\Models\ContractChange;
use App\Models\DailyClosingReport;
use App\Models\DailyTradeReport;
use App\Models\DailyWorkAssignment;
use App\Models\EmailThread;
use App\Models\EmployeeBadgeQrToken;
use App\Models\Equipment;
use App\Models\EquipmentChecklistTemplate;
use App\Models\ExpensePreApproval;
use App\Models\FieldDrawing;
use App\Models\IntelligentDocument;
use App\Models\ItemCategory;
use App\Models\MailboxConnection;
use App\Models\MailThread;
use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptLine;
use App\Models\MemberRegistration;
use App\Models\OpsIntakeBatch;
use App\Models\PayApplication;
use App\Models\Payslip;
use App\Models\PhotoUpload;
use App\Models\ProcurementItem;
use App\Models\ProjectContract;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\PurchaseRequestOrder;
use App\Models\SafetyWorkItem;
use App\Models\SiteContractor;
use App\Models\Submittal;
use App\Models\Vehicle;
use App\Models\WbsItem;
use App\Models\WorkSection;

// Trusted parent chains for records with no direct company/site ownership.
return [
    'PurchaseRequestEvent' => [['purchase_request_id', PurchaseRequest::class]],
    'PurchaseRequestOrder' => [['purchase_request_id', PurchaseRequest::class]],
    'PurchaseRequestOrderLine' => [['purchase_request_order_id', PurchaseRequestOrder::class], ['purchase_request_line_id', PurchaseRequestLine::class]],
    'AiOutput' => [['ai_job_id', AiJob::class]],
    'AttendanceLog' => [['daily_work_assignment_id', DailyWorkAssignment::class], ['attendance_qr_code_id', AttendanceQrCode::class], ['employee_badge_qr_token_id', EmployeeBadgeQrToken::class], ['site_contractor_id', SiteContractor::class], ['photo_upload_id', PhotoUpload::class]],
    'AttendanceQrCode' => [['site_contractor_id', SiteContractor::class]],
    'BillingReceipt' => [['project_contract_id', ProjectContract::class], ['pay_application_id', PayApplication::class]],
    'BoqItem' => [['source_document_id', IntelligentDocument::class]],
    'ClaimWorkRecord' => [['contract_boq_line_id', ContractBoqLine::class]],
    'CommunicationMessage' => [['communication_room_id', CommunicationRoom::class]],
    'CommunicationMessageFile' => [['communication_message_id', CommunicationMessage::class], ['intelligent_document_id', IntelligentDocument::class]],
    'CommunicationMessageReaction' => [['communication_message_id', CommunicationMessage::class]],
    'CommunicationMessageRead' => [['communication_message_id', CommunicationMessage::class], ['communication_room_id', CommunicationRoom::class]],
    'CommunicationNotification' => [['communication_room_id', CommunicationRoom::class], ['communication_message_id', CommunicationMessage::class]],
    'CommunicationRoomMember' => [['communication_room_id', CommunicationRoom::class]],
    'ContractBoqLine' => [['work_section_id', WorkSection::class], ['project_contract_id', ProjectContract::class]],
    'ContractChange' => [['project_contract_id', ProjectContract::class]],
    'ContractChangeLine' => [['contract_change_id', ContractChange::class], ['contract_boq_line_id', ContractBoqLine::class]],
    'DailyCrewReport' => [['site_contractor_id', SiteContractor::class], ['attendance_qr_code_id', AttendanceQrCode::class]],
    'DailyWorkAssignment' => [['site_contractor_id', SiteContractor::class]],
    'DocumentActionItem' => [['intelligent_document_id', IntelligentDocument::class]],
    'DrawingMark' => [['contract_boq_line_id', ContractBoqLine::class], ['claim_work_record_id', ClaimWorkRecord::class], ['work_section_id', WorkSection::class]],
    'DrawingSheet' => [['intelligent_document_id', IntelligentDocument::class]],
    'EmailMessage' => [['email_thread_id', EmailThread::class], ['intelligent_document_id', IntelligentDocument::class]],
    'EmailThread' => [['mailbox_connection_id', MailboxConnection::class]],
    'EquipmentChecklistItem' => [['equipment_checklist_template_id', EquipmentChecklistTemplate::class]],
    'EquipmentChecklistLog' => [['equipment_id', Equipment::class], ['equipment_checklist_template_id', EquipmentChecklistTemplate::class]],
    'EquipmentRental' => [['equipment_id', Equipment::class]],
    'FieldDrawingMessage' => [['field_drawing_id', FieldDrawing::class]],
    'IntegratedDocument' => [['procurement_item_id', ProcurementItem::class]],
    'IntelligentDocument' => [['project_contract_id', ProjectContract::class], ['email_thread_id', EmailThread::class]],
    'Item' => [['item_category_id', ItemCategory::class]],
    'KnowledgeFact' => [['intelligent_document_id', IntelligentDocument::class]],
    'MailMessage' => [['mail_thread_id', MailThread::class]],
    'MaterialClaimLink' => [['contract_boq_line_id', ContractBoqLine::class]],
    'MaterialReceiptLine' => [['material_receipt_id', MaterialReceipt::class]],
    'MemberDocument' => [['member_registration_id', MemberRegistration::class]],
    'MobileExpense' => [['expense_pre_approval_id', ExpensePreApproval::class]],
    'OcrResult' => [['photo_upload_id', PhotoUpload::class]],
    'OpsIntakeBatch' => [['daily_trade_report_id', DailyTradeReport::class]],
    'OpsIntakeItem' => [['ops_intake_batch_id', OpsIntakeBatch::class], ['communication_message_id', CommunicationMessage::class]],
    'OpsMeeting' => [['ops_intake_batch_id', OpsIntakeBatch::class]],
    'PayApplication' => [['project_contract_id', ProjectContract::class], ['intelligent_document_id', IntelligentDocument::class]],
    'PayApplicationAllocation' => [['pay_application_id', PayApplication::class], ['claim_work_record_id', ClaimWorkRecord::class]],
    'PayrollTimesheet' => [['site_contractor_id', SiteContractor::class]],
    'PayslipLine' => [['payslip_id', Payslip::class]],
    'ProcurementItem' => [['contract_id', ProjectContract::class], ['wbs_item_id', WbsItem::class]],
    'ProjectContractDocument' => [['project_contract_id', ProjectContract::class]],
    'PurchaseReceiptAllocation' => [['material_receipt_line_id', MaterialReceiptLine::class]],
    'PurchaseRequestAttachment' => [['purchase_request_id', PurchaseRequest::class]],
    'PurchaseRequestLine' => [['purchase_request_id', PurchaseRequest::class]],
    'ReportDispatch' => [['daily_closing_report_id', DailyClosingReport::class], ['intelligent_document_id', IntelligentDocument::class]],
    'SafetyPermit' => [['safety_work_item_id', SafetyWorkItem::class]],
    'SafetyWorkIssue' => [['safety_work_item_id', SafetyWorkItem::class]],
    'SafetyWorkSignature' => [['safety_work_item_id', SafetyWorkItem::class]],
    'Submittal' => [['source_document_id', IntelligentDocument::class]],
    'SubmittalEvent' => [['submittal_id', Submittal::class], ['intelligent_document_id', IntelligentDocument::class]],
    'VehicleRental' => [['vehicle_id', Vehicle::class]],
    'WorkSectionSheet' => [['work_section_id', WorkSection::class]],
];
